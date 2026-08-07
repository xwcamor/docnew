<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\Laboratories\BulkLaboratoriesActionJob;
use App\Models\AuditLog;
use App\Models\Laboratory;
use Illuminate\Support\Facades\DB;

/**
 * LaboratoryService â€” operaciones de negocio del modulo laboratories.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class LaboratoryService
{
    public function create(array $data): Laboratory
    {
        $laboratory = new Laboratory($data);
        $laboratory->created_by = auth()->id();
        $laboratory->save();
        return $laboratory;
    }

    public function update(Laboratory $laboratory, array $data): Laboratory
    {
        $laboratory->update($data);
        return $laboratory;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(Laboratory $laboratory, string $reason): void
    {
        $laboratory->deleted_description = $reason;
        $laboratory->deleted_by          = auth()->id();
        $laboratory->is_active           = false;
        $laboratory->saveQuietly();
        $laboratory->delete();
    }

    public function restore(Laboratory $laboratory): Laboratory
    {
        $laboratory->deleted_description = null;
        $laboratory->deleted_by          = null;
        $laboratory->restore();
        return $laboratory;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(Laboratory $laboratory, string $reason): void
    {
        DB::transaction(function () use ($laboratory, $reason) {
            $locked = Laboratory::onlyTrashed()->where('id', $laboratory->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Laboratory {$laboratory->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Laboratory::class,
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
                'module'         => 'laboratories',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el laboratory. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(Laboratory $laboratory): ?Laboratory
    {
        $base    = $laboratory->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($laboratory, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Laboratory::query()
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

            $clone = new Laboratory($laboratory->only(['is_active', 'sort_order']));
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
    // BulkLaboratoriesActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkLaboratoriesActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkLaboratoriesActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $laboratories  = Laboratory::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($laboratories as $laboratory) {
                $this->delete($laboratory, $reason);
                $deletedIds[] = $laboratory->id;
            }
            return ['queued' => false, 'count' => $laboratories->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkLaboratoriesActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $laboratories = Laboratory::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($laboratories as $laboratory) {
                if ((bool) $laboratory->is_active === $isActive) continue;
                $laboratory->update(['is_active' => $isActive]);
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
            BulkLaboratoriesActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $laboratories = Laboratory::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($laboratories as $laboratory) {
                $this->restore($laboratory);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $laboratories->count()];
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
        $laboratories = Laboratory::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($laboratories as $laboratory) {
            $this->restore($laboratory);
            $restored[] = $laboratory->id;
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
            $byId  = Laboratory::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $laboratory = $byId[$change['id']] ?? null;
                if (!$laboratory) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $laboratory->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $laboratory->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
