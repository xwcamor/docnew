<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\Companies\BulkCompaniesActionJob;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * CompanyService â€” operaciones de negocio del modulo companies.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class CompanyService
{
    public function create(array $data): Company
    {
        $company = new Company($data);
        $company->created_by = auth()->id();
        $company->save();
        return $company;
    }

    public function update(Company $company, array $data): Company
    {
        $company->update($data);
        return $company;
    }

    /**
     * ¿Por qué no se puede borrar? Devuelve el texto que ve el usuario, o null
     * si la empresa no tiene nada colgando. Mismo patrón que los catálogos de
     * obra (WorkAreaService::motivoParaNoBorrar).
     */
    public function motivoParaNoBorrar(Company $company): ?string
    {
        $deps = $company->countDependents();

        $planes   = (int) ($deps['work_plans']['count'] ?? 0);
        $personas = (int) ($deps['people']['count'] ?? 0);

        if ($planes > 0) {
            return __('companies.in_use_cannot_delete_plans', ['count' => $planes]);
        }
        if ($personas > 0) {
            return __('companies.in_use_cannot_delete_people', ['count' => $personas]);
        }

        return null;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     *
     * Si de la empresa cuelgan planes o gente, no se borra: se lanza
     * DomainException y el controlador la convierte en un aviso en pantalla.
     */
    public function delete(Company $company, string $reason): void
    {
        if ($razon = $this->motivoParaNoBorrar($company)) {
            throw new \DomainException($razon);
        }

        $company->deleted_description = $reason;
        $company->deleted_by          = auth()->id();
        $company->is_active           = false;
        $company->saveQuietly();
        $company->delete();
    }

    public function restore(Company $company): Company
    {
        $company->deleted_description = null;
        $company->deleted_by          = null;
        $company->restore();
        return $company;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Company $company, string $reason): void
    {
        DB::transaction(function () use ($company, $reason) {
            $locked = Company::onlyTrashed()->where('id', $company->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Company {$company->id} no longer available for force-delete");
            }

            // Los planes y la gente apuntan a la empresa con `restrictOnDelete`:
            // sin este freno el borrado definitivo reventaba con un error de
            // clave ajena de Postgres en la cara del super.
            if ($razon = $this->motivoParaNoBorrar($locked)) {
                throw new \DomainException($razon);
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Company::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name' => $locked->name,
                    // Era `$locked->code`: esa columna no existe en companies
                    // (resto del clon de Brand), asi que el RUC de la empresa
                    // borrada para siempre se guardaba vacio en la auditoria.
                    'num_doc' => $locked->num_doc,
                    'slug' => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'companies',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el company. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El RUC NO se copia (es unico por pais y workspace): el clon nace con un
     * marcador que el usuario debe reemplazar al editarlo.
     */
    public function duplicate(Company $company): ?Company
    {
        $base    = $company->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($company, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Company::query()
                    ->when($isPgsql,
                        fn ($q) => $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$candidate]),
                        fn ($q) => $q->whereRaw('LOWER(name) = LOWER(?)', [$candidate]),
                    )
                    ->lockForUpdate()
                    ->exists();

                if (!$exists) break;
                $candidate = $base . ' ' . $i;
                $i++;
                if ($i > 100) return null;
            }

            $clone = new Company($company->only(['is_active', 'complete_name', 'country_id']));
            $clone->name       = $candidate;
            // RUC provisional unico: la tabla lo exige NOT NULL y unico.
            $clone->num_doc    = 'COPIA-' . strtoupper(\Illuminate\Support\Str::random(8));
            $clone->created_by = auth()->id();
            $clone->save();

            return $clone;
        });
    }

    // â”€â”€â”€ Bulk ops â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    //
    // Auto-async: si count(ids) excede el umbral, dispatchamos el job y
    // devolvemos un payload "queued" para que el controller redirija con
    // mensaje de cola. Bajo el umbral, corre inline. El umbral vive en
    // BulkCompaniesActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkCompaniesActionJob::asyncThreshold();
    }

    /**
     * Borrado masivo. Las empresas con planes o gente colgando se apartan en
     * `in_use` en vez de tumbar el lote entero: seleccionar veinte y que no se
     * borre ninguna porque una tiene un plan no le sirve a nadie.
     *
     * @return array{queued: bool, count: int, deleted?: int[], in_use?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkCompaniesActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => [], 'in_use' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $companies  = Company::whereIn('id', $ids)->get();
            $deletedIds = [];
            $inUseIds   = [];
            foreach ($companies as $company) {
                try {
                    $this->delete($company, $reason);
                } catch (\DomainException) {
                    $inUseIds[] = $company->id;
                    continue;
                }
                $deletedIds[] = $company->id;
            }
            return [
                'queued'  => false,
                'count'   => $companies->count(),
                'deleted' => $deletedIds,
                'in_use'  => $inUseIds,
            ];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkCompaniesActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $companies = Company::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($companies as $company) {
                if ((bool) $company->is_active === $isActive) continue;
                $company->update(['is_active' => $isActive]);
                $changed++;
            }
            return ['queued' => false, 'count' => $count, 'changed' => $changed];
        });
    }

    /**
     * @return array{queued: bool, count: int, restored?: int}
     */
    public function bulkRestore(array $ids): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkCompaniesActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $companies = Company::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($companies as $company) {
                $this->restore($company);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $companies->count()];
        });
    }

    /**
     * Undo dentro del window de 60s. Defense in depth: solo restaura las filas
     * que matchean deleted_by = userId, no cualquier id del claim.
     *
     * @param int[] $claimIds
     * @return int[] ids efectivamente restaurados
     */
    public function undoLastDelete(array $claimIds, int $userId): array
    {
        $companies = Company::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($companies as $company) {
            $this->restore($company);
            $restored[] = $company->id;
        }
        return $restored;
    }

    /**
     * Batch update de name + is_active. Persistencia en transaccion para
     * atomicidad. Skip filas sin cambio real para evitar audit log noise.
     *
     * @return int touched count
     */
    public function editAllUpdate(array $changes): int
    {
        $touched = 0;

        DB::transaction(function () use ($changes, &$touched) {
            $ids   = array_column($changes, 'id');
            $byId  = Company::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $company = $byId[$change['id']] ?? null;
                if (!$company) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $company->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $company->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
