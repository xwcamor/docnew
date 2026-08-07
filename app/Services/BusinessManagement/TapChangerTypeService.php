<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\TapChangerTypes\BulkTapChangerTypesActionJob;
use App\Models\AuditLog;
use App\Models\TapChangerType;
use Illuminate\Support\Facades\DB;

/**
 * TapChangerTypeService â€” operaciones de negocio del modulo tap_changer_types.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class TapChangerTypeService
{
    public function create(array $data): TapChangerType
    {
        $tapChangerType = new TapChangerType($data);
        $tapChangerType->created_by = auth()->id();
        $tapChangerType->save();
        return $tapChangerType;
    }

    public function update(TapChangerType $tapChangerType, array $data): TapChangerType
    {
        $tapChangerType->update($data);
        return $tapChangerType;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(TapChangerType $tapChangerType, string $reason): void
    {
        $tapChangerType->deleted_description = $reason;
        $tapChangerType->deleted_by          = auth()->id();
        $tapChangerType->is_active           = false;
        $tapChangerType->saveQuietly();
        $tapChangerType->delete();
    }

    public function restore(TapChangerType $tapChangerType): TapChangerType
    {
        $tapChangerType->deleted_description = null;
        $tapChangerType->deleted_by          = null;
        $tapChangerType->restore();
        return $tapChangerType;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(TapChangerType $tapChangerType, string $reason): void
    {
        DB::transaction(function () use ($tapChangerType, $reason) {
            $locked = TapChangerType::onlyTrashed()->where('id', $tapChangerType->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("TapChangerType {$tapChangerType->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => TapChangerType::class,
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
                'module'         => 'tap_changer_types',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el tapChangerType. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(TapChangerType $tapChangerType): ?TapChangerType
    {
        $base    = $tapChangerType->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($tapChangerType, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = TapChangerType::query()
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

            $clone = new TapChangerType($tapChangerType->only(['is_active', 'sort_order']));
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
    // BulkTapChangerTypesActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkTapChangerTypesActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTapChangerTypesActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $tapChangerTypes  = TapChangerType::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($tapChangerTypes as $tapChangerType) {
                $this->delete($tapChangerType, $reason);
                $deletedIds[] = $tapChangerType->id;
            }
            return ['queued' => false, 'count' => $tapChangerTypes->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkTapChangerTypesActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $tapChangerTypes = TapChangerType::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($tapChangerTypes as $tapChangerType) {
                if ((bool) $tapChangerType->is_active === $isActive) continue;
                $tapChangerType->update(['is_active' => $isActive]);
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
            BulkTapChangerTypesActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $tapChangerTypes = TapChangerType::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($tapChangerTypes as $tapChangerType) {
                $this->restore($tapChangerType);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $tapChangerTypes->count()];
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
        $tapChangerTypes = TapChangerType::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($tapChangerTypes as $tapChangerType) {
            $this->restore($tapChangerType);
            $restored[] = $tapChangerType->id;
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
            $byId  = TapChangerType::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $tapChangerType = $byId[$change['id']] ?? null;
                if (!$tapChangerType) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $tapChangerType->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $tapChangerType->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
