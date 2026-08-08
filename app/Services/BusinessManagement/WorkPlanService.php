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
    public function __construct(
        private readonly WorkPlanCodeGenerator $codigos,
        private readonly WorkPlanSetupService $armado,
        private readonly WorkPlanCompletionService $cierre,
    ) {
    }

    public function create(array $data): WorkPlan
    {
        $workPlan = new WorkPlan($data);
        // user_id = quien registra el plan. Es NOT NULL y no se pide en el
        // formulario: siempre es el usuario de la sesión.
        $workPlan->user_id    = $data['user_id'] ?? auth()->id();
        $workPlan->created_by = auth()->id();

        // El código lo pone el sistema, no la persona: es el correlativo del
        // día del trabajo y por construcción no puede repetirse. Ver
        // WorkPlanCodeGenerator, que explica por qué se dejó de usar la hora.
        if (blank($workPlan->code)) {
            $workPlan->code = $this->codigos->siguiente(
                $workPlan->country_id,
                $workPlan->tenant_id ?? auth()->user()?->tenant_id,
                $workPlan->date_start,
            );
        }

        $workPlan->save();

        // Los aprobadores por defecto salen de las reglas de aprobación del
        // país: quedan propuestos sin persona asignada, para que el supervisor
        // ponga el nombre. Es lo que impide que un plan salga a obra sin firma
        // de HSE porque alguien no se acordó de pedirla.
        $this->armado->seedApprovalsFromRules($workPlan);

        return $workPlan;
    }

    /**
     * Al guardar se comprueba si el plan ya puede darse por cerrado.
     *
     * Es el `after_save :lock_plan_if_all_conditions_met` del sistema anterior,
     * y aqui el disparador que importa es poner la hora de fin: lo normal es
     * que las firmas ya estén y lo último que llega sea el fin de jornada.
     */
    public function update(WorkPlan $workPlan, array $data): WorkPlan
    {
        $workPlan->update($data);

        $this->cierre->evaluar($workPlan);

        return $workPlan->refresh();
    }

    /**
     * Soft-delete con motivo. saveQuietly() evita un audit log `updated`
     * duplicado justo antes del `deleted`.
     */
    public function delete(WorkPlan $workPlan, string $reason): void
    {
        // Un plan no se "desactiva" al borrarlo: no tiene is_active, pasa a
        // papelera con su motivo y nada más.
        $workPlan->deleted_description = $reason;
        $workPlan->deleted_by          = auth()->id();
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
                    'code'   => $locked->code,
                    'num_os' => $locked->num_os,
                    'slug'   => $locked->slug,
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
     * Clona el plan para volver a levantar el mismo trabajo otro día: copia
     * empresa, tipo, sede y descripción, pero NACE PENDIENTE y sin candado —
     * las firmas y los formatos del original no se heredan.
     *
     * El código es único por tenant, así que se le agrega sufijo "-COPIA" (y
     * -COPIA-2, -3…) con sanity guard de 100 intentos.
     */
    public function duplicate(WorkPlan $workPlan): ?WorkPlan
    {
        $base = $workPlan->code . '-' . strtoupper(__('global.duplicate_suffix'));

        return DB::transaction(function () use ($workPlan, $base) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = WorkPlan::query()
                    ->whereRaw('UPPER(code) = UPPER(?)', [$candidate])
                    ->lockForUpdate()
                    ->exists();

                if (!$exists) break;
                $candidate = $base . '-' . $i;
                $i++;
                if ($i > 100) return null;
            }

            $clone = new WorkPlan($workPlan->only([
                'country_id', 'company_id', 'work_type_id', 'work_location_id',
                'workstation_id', 'work_area_id', 'num_os', 'description',
                'date_start', 'date_end',
            ]));
            $clone->code       = $candidate;
            $clone->user_id    = auth()->id() ?? $workPlan->user_id;
            $clone->is_done    = false;
            $clone->is_closed  = false;
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
     * Marca/desmarca planes como terminados. El parámetro sigue llamándose
     * `is_active` en el request porque lo emite el composable de bulk que
     * comparten todos los módulos; aquí se traduce a `is_done`, que es el
     * estado que sí existe en un plan.
     *
     * @return array{queued: bool, count: int, changed?: int}
     */
    public function bulkSetActive(array $ids, bool $isDone): array
    {
        $count = count($ids);

        if ($this->shouldDispatchAsync($count)) {
            BulkWorkPlansActionJob::dispatch(
                (int) auth()->id(),
                'set_active',
                $ids,
                ['is_active' => $isDone],
            );
            return ['queued' => true, 'count' => $count];
        }

        return DB::transaction(function () use ($ids, $isDone, $count) {
            $work_plans = WorkPlan::whereIn('id', $ids)->get();
            $changed   = 0;
            foreach ($work_plans as $workPlan) {
                if ((bool) $workPlan->is_done === $isDone) continue;
                $workPlan->update(['is_done' => $isDone]);
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
     * Batch update de num_os + is_done. Persistencia en transaccion para
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
                    array_intersect_key($change, array_flip(['num_os', 'is_done'])),
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
