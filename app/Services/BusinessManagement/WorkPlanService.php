<?php

namespace App\Services\BusinessManagement;

use App\Jobs\BusinessManagement\WorkPlans\BulkWorkPlansActionJob;
use App\Models\AuditLog;
use App\Models\WorkPlan;
use Illuminate\Support\Facades\DB;

/**
 * WorkPlanService â€” operaciones de negocio del modulo work_plans.
 *
 * Clon del patron de RegionService/RoleService: el controller queda thin
 * y delega aquí toda la mutacion de datos. Mantiene los audit logs cerca
 * de la operacion (Auditable trait dispara en created/updated/deleted/
 * restored; force_delete escribe el audit manual).
 *
 * NO maneja exports/imports/list: esa es orquestacion HTTP y vive en el
 * controller.
 */
class WorkPlanService
{
    public function create(array $data): WorkPlan
    {
        $workPlan = new WorkPlan($data);
        $workPlan->created_by = auth()->id();
        $workPlan->save();
        return $workPlan;
    }

    public function update(WorkPlan $workPlan, array $data): WorkPlan
    {
        $workPlan->update($data);
        return $workPlan;
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(WorkPlan $workPlan, string $reason): void
    {
        $workPlan->deleted_description = $reason;
        $workPlan->deleted_by          = auth()->id();
        $workPlan->is_active           = false;
        $workPlan->saveQuietly();
        $workPlan->delete();
    }

    public function restore(WorkPlan $workPlan): WorkPlan
    {
        $workPlan->deleted_description = null;
        $workPlan->deleted_by          = null;
        $workPlan->restore();
        return $workPlan;
    }

    /**
     * Hard delete. Audit ANTES del delete (sobrevive al borrado) + transaccion
     * para atomicidad. lockForUpdate previene race con un restore concurrente.
     */
    public function forceDelete(WorkPlan $workPlan, string $reason): void
    {
        DB::transaction(function () use ($workPlan, $reason) {
            $locked = WorkPlan::onlyTrashed()->where('id', $workPlan->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("WorkPlan {$workPlan->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => WorkPlan::class,
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
                'module'         => 'work_plans',
                'created_at'     => now(),
            ]);

            $locked->forceDelete();
        });
    }

    /**
     * Clona el workPlan. Sufijo "(copia)" con sanity guard de 100 intentos.
     * El `cod` no se copia (es unique por tenant â€” se deja en null para que
     * el usuario lo ajuste manualmente al editar el clon).
     */
    public function duplicate(WorkPlan $workPlan): ?WorkPlan
    {
        $base    = $workPlan->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($workPlan, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = WorkPlan::query()
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

            $clone = new WorkPlan($workPlan->only(['is_active', 'num_os']));
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
    // BulkWorkPlansActionJob::asyncThreshold() (Setting global -> config).

    public function shouldDispatchAsync(int $count): bool
    {
        return $count > BulkWorkPlansActionJob::asyncThreshold();
    }

    /**
     * @return array{queued: bool, count: int, deleted?: int[]}
     */
    public function bulkDelete(array $ids, string $reason): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkWorkPlansActionJob::dispatch(
                (int) auth()->id(),
                'delete',
                $ids,
                ['reason' => $reason],
            );
            return ['queued' => true, 'count' => $count, 'deleted' => []];
        }

        return DB::transaction(function () use ($ids, $reason) {
            $work_plans  = WorkPlan::whereIn('id', $ids)->get();
            $deletedIds = [];
            foreach ($work_plans as $workPlan) {
                $this->delete($workPlan, $reason);
                $deletedIds[] = $workPlan->id;
            }
            return ['queued' => false, 'count' => $work_plans->count(), 'deleted' => $deletedIds];
        });
    }

    /**
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isActive): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkWorkPlansActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isActive],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isActive, $count) {
            $work_plans = WorkPlan::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($work_plans as $workPlan) {
                if ((bool) $workPlan->is_active === $isActive) continue;
                $workPlan->update(['is_active' => $isActive]);
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
            BulkWorkPlansActionJob::dispatch(
                (int) auth()->id(),
                'restore',
                $ids,
                [],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $count) {
            $work_plans = WorkPlan::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($work_plans as $workPlan) {
                $this->restore($workPlan);
            }
            return ['queued' => false, 'count' => $count, 'restored' => $work_plans->count()];
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
        $work_plans = WorkPlan::onlyTrashed()
            ->whereIn('id', $claimIds)
            ->where('deleted_by', $userId)
            ->get();

        $restored = [];
        foreach ($work_plans as $workPlan) {
            $this->restore($workPlan);
            $restored[] = $workPlan->id;
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
            $byId  = WorkPlan::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($changes as $change) {
                $workPlan = $byId[$change['id']] ?? null;
                if (!$workPlan) continue;

                $patch = array_filter(
                    array_intersect_key($change, array_flip(['name', 'is_active'])),
                    fn ($v) => $v !== null,
                );
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $workPlan->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $workPlan->fill($patch)->save();
                $touched++;
            }
        });

        return $touched;
    }
}
