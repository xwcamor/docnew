<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\People\BulkPeopleActionJob;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PersonCompanyLink;
use Illuminate\Support\Facades\DB;

/**
 * PersonService â€” operaciones de negocio del modulo people.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class PersonService
{
    public function create(array $data): Person
    {
        $person = new Person($data);
        $person->created_by = auth()->id();
        $person->save();
        return $person;
    }

    public function update(Person $person, array $data): Person
    {
        $person->update($data);
        return $person;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(Person $person, string $reason): void
    {
        $person->deleted_description = $reason;
        $person->deleted_by          = auth()->id();
        $person->is_active           = false;
        $person->saveQuietly();
        $person->delete();
    }

    public function restore(Person $person): Person
    {
        $person->deleted_description = null;
        $person->deleted_by          = null;
        $person->restore();
        return $person;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Person $person, string $reason): void
    {
        DB::transaction(function () use ($person, $reason) {
            $locked = Person::onlyTrashed()->where('id', $person->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Person {$person->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Person::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name' => $locked->name,
                    'num_doc' => $locked->code,
                    'slug' => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'people',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el person. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(Person $person): ?Person
    {
        $base    = $person->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($person, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Person::query()
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

            $clone = new Person($person->only(['is_active', 'lastname']));
            $clone->name       = $candidate;
            $clone->code       = null;
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
    // BulkPeopleActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkPeopleActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkPeopleActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $people  = Person::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($people as $person) {
                $this->delete($person, $reason);
                $deletedIds[] = $person->id;
            }
            return ['queued' => false, 'count' => $people->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkPeopleActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $people = Person::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($people as $person) {
                if ((bool) $person->is_active === $isActive) continue;
                $person->update(['is_active' => $isActive]);
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
            BulkPeopleActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $people = Person::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($people as $person) {
                $this->restore($person);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $people->count()];
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
        $people = Person::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($people as $person) {
            $this->restore($person);
            $restored[] = $person->id;
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
            $byId  = Person::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $person = $byId[$change['id']] ?? null;
                if (!$person) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $person->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $person->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }

    /**
     * El alta de una persona desde un plan de trabajo.
     *
     * Esta es la correccion de fondo respecto al sistema viejo: alli cada alta
     * creaba un Worker nuevo por empresa, y la misma persona terminaba con una
     * identidad, una foto y una firma distintas en cada contratista (391 filas
     * para 231 personas reales). Aqui la identidad se busca primero por su
     * documento; si ya existe, solo se crea el vinculo con la empresa y la
     * persona conserva su biometria y su firma.
     *
     * @return array{persona: Person, creada: bool}
     */
    public function vincularOCrear(array $datos, int $companyId, ?int $positionId = null): array
    {
        return DB::transaction(function () use ($datos, $companyId, $positionId) {
            $persona = Person::withTrashed()
                ->where('country_id', $datos['country_id'])
                ->where('doc_type', $datos['doc_type'] ?? 'DNI')
                ->where('num_doc', $datos['num_doc'])
                ->first();

            $creada = false;

            if (! $persona) {
                $persona = Person::create($datos);
                $creada = true;
            } elseif ($persona->trashed()) {
                // Se restaura, no se duplica: la persona vuelve con su historial.
                $persona->restore();
            }

            PersonCompanyLink::updateOrCreate(
                ['person_id' => $persona->id, 'company_id' => $companyId],
                ['position_id' => $positionId, 'is_active' => true, 'started_on' => now()],
            );

            if (! $persona->hasRole(PersonRole::WORKER)) {
                PersonRole::updateOrCreate(
                    ['person_id' => $persona->id, 'role' => PersonRole::WORKER],
                    ['is_active' => true],
                );
            }

            return ['persona' => $persona->fresh(), 'creada' => $creada];
        });
    }
}
