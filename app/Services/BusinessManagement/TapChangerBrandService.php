<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\TapChangerBrands\BulkTapChangerBrandsActionJob;
use App\Models\AuditLog;
use App\Models\TapChangerBrand;
use Illuminate\Support\Facades\DB;

/**
 * TapChangerBrandService â€” operaciones de negocio del modulo tap_changer_brands.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class TapChangerBrandService
{
    public function create(array $data): TapChangerBrand
    {
        $tapChangerBrand = new TapChangerBrand($data);
        $tapChangerBrand->created_by = auth()->id();
        $tapChangerBrand->save();
        return $tapChangerBrand;
    }

    public function update(TapChangerBrand $tapChangerBrand, array $data): TapChangerBrand
    {
        $tapChangerBrand->update($data);
        return $tapChangerBrand;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(TapChangerBrand $tapChangerBrand, string $reason): void
    {
        $tapChangerBrand->deleted_description = $reason;
        $tapChangerBrand->deleted_by          = auth()->id();
        $tapChangerBrand->is_active           = false;
        $tapChangerBrand->saveQuietly();
        $tapChangerBrand->delete();
    }

    public function restore(TapChangerBrand $tapChangerBrand): TapChangerBrand
    {
        $tapChangerBrand->deleted_description = null;
        $tapChangerBrand->deleted_by          = null;
        $tapChangerBrand->restore();
        return $tapChangerBrand;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(TapChangerBrand $tapChangerBrand, string $reason): void
    {
        DB::transaction(function () use ($tapChangerBrand, $reason) {
            $locked = TapChangerBrand::onlyTrashed()->where('id', $tapChangerBrand->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("TapChangerBrand {$tapChangerBrand->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => TapChangerBrand::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name' => $locked->name,
                    'code' => $locked->code,
                    'slug' => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'tap_changer_brands',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el tapChangerBrand. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(TapChangerBrand $tapChangerBrand): ?TapChangerBrand
    {
        $base    = $tapChangerBrand->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($tapChangerBrand, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = TapChangerBrand::query()
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

            $clone = new TapChangerBrand($tapChangerBrand->only(['is_active', 'sort_order']));
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
    // BulkTapChangerBrandsActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkTapChangerBrandsActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTapChangerBrandsActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $tapChangerBrands  = TapChangerBrand::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($tapChangerBrands as $tapChangerBrand) {
                $this->delete($tapChangerBrand, $reason);
                $deletedIds[] = $tapChangerBrand->id;
            }
            return ['queued' => false, 'count' => $tapChangerBrands->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTapChangerBrandsActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $tapChangerBrands = TapChangerBrand::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($tapChangerBrands as $tapChangerBrand) {
                if ((bool) $tapChangerBrand->is_active === $isActive) continue;
                $tapChangerBrand->update(['is_active' => $isActive]);
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
            BulkTapChangerBrandsActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $tapChangerBrands = TapChangerBrand::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($tapChangerBrands as $tapChangerBrand) {
                $this->restore($tapChangerBrand);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $tapChangerBrands->count()];
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
        $tapChangerBrands = TapChangerBrand::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($tapChangerBrands as $tapChangerBrand) {
            $this->restore($tapChangerBrand);
            $restored[] = $tapChangerBrand->id;
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
            $byId  = TapChangerBrand::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $tapChangerBrand = $byId[$change['id']] ?? null;
                if (!$tapChangerBrand) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $tapChangerBrand->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $tapChangerBrand->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
