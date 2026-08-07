<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\OilTypes\BulkOilTypesActionJob;
use App\Models\AuditLog;
use App\Models\OilType;
use Illuminate\Support\Facades\DB;

/**
 * OilTypeService â€” operaciones de negocio del modulo oil_types.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class OilTypeService
{
    public function create(array $data): OilType
    {
        $oilType = new OilType($data);
        $oilType->created_by = auth()->id();
        $oilType->save();
        return $oilType;
    }

    public function update(OilType $oilType, array $data): OilType
    {
        $oilType->update($data);
        return $oilType;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(OilType $oilType, string $reason): void
    {
        $oilType->deleted_description = $reason;
        $oilType->deleted_by          = auth()->id();
        $oilType->is_active           = false;
        $oilType->saveQuietly();
        $oilType->delete();
    }

    public function restore(OilType $oilType): OilType
    {
        $oilType->deleted_description = null;
        $oilType->deleted_by          = null;
        $oilType->restore();
        return $oilType;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(OilType $oilType, string $reason): void
    {
        DB::transaction(function () use ($oilType, $reason) {
            $locked = OilType::onlyTrashed()->where('id', $oilType->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("OilType {$oilType->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => OilType::class,
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
                'module'         => 'oil_types',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el oilType. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(OilType $oilType): ?OilType
    {
        $base    = $oilType->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($oilType, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = OilType::query()
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

            $clone = new OilType($oilType->only(['is_active', 'sort_order']));
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
    // BulkOilTypesActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkOilTypesActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkOilTypesActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $oilTypes  = OilType::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($oilTypes as $oilType) {
                $this->delete($oilType, $reason);
                $deletedIds[] = $oilType->id;
            }
            return ['queued' => false, 'count' => $oilTypes->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkOilTypesActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $oilTypes = OilType::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($oilTypes as $oilType) {
                if ((bool) $oilType->is_active === $isActive) continue;
                $oilType->update(['is_active' => $isActive]);
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
            BulkOilTypesActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $oilTypes = OilType::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($oilTypes as $oilType) {
                $this->restore($oilType);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $oilTypes->count()];
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
        $oilTypes = OilType::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($oilTypes as $oilType) {
            $this->restore($oilType);
            $restored[] = $oilType->id;
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
            $byId  = OilType::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $oilType = $byId[$change['id']] ?? null;
                if (!$oilType) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $oilType->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $oilType->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
