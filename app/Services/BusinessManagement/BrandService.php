<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\Brands\BulkBrandsActionJob;
use App\Models\AuditLog;
use App\Models\Brand;
use Illuminate\Support\Facades\DB;

/**
 * BrandService â€” operaciones de negocio del modulo brands.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class BrandService
{
    public function create(array $data): Brand
    {
        $brand = new Brand($data);
        $brand->created_by = auth()->id();
        $brand->save();
        return $brand;
    }

    public function update(Brand $brand, array $data): Brand
    {
        $brand->update($data);
        return $brand;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(Brand $brand, string $reason): void
    {
        $brand->deleted_description = $reason;
        $brand->deleted_by          = auth()->id();
        $brand->is_active           = false;
        $brand->saveQuietly();
        $brand->delete();
    }

    public function restore(Brand $brand): Brand
    {
        $brand->deleted_description = null;
        $brand->deleted_by          = null;
        $brand->restore();
        return $brand;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Brand $brand, string $reason): void
    {
        DB::transaction(function () use ($brand, $reason) {
            $locked = Brand::onlyTrashed()->where('id', $brand->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Brand {$brand->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Brand::class,
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
                'module'         => 'brands',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el brand. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(Brand $brand): ?Brand
    {
        $base    = $brand->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($brand, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Brand::query()
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

            $clone = new Brand($brand->only(['is_active', 'sort_order']));
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
    // BulkBrandsActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkBrandsActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkBrandsActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $brands  = Brand::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($brands as $brand) {
                $this->delete($brand, $reason);
                $deletedIds[] = $brand->id;
            }
            return ['queued' => false, 'count' => $brands->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkBrandsActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $brands = Brand::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($brands as $brand) {
                if ((bool) $brand->is_active === $isActive) continue;
                $brand->update(['is_active' => $isActive]);
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
            BulkBrandsActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $brands = Brand::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($brands as $brand) {
                $this->restore($brand);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $brands->count()];
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
        $brands = Brand::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($brands as $brand) {
            $this->restore($brand);
            $restored[] = $brand->id;
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
            $byId  = Brand::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $brand = $byId[$change['id']] ?? null;
                if (!$brand) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $brand->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $brand->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
