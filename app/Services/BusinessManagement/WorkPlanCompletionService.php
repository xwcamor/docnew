<?php

namespace App\Services\BusinessManagement;

use App\Models\WorkPlan;

/**
 * El plan se cierra solo cuando ya no falta nada.
 *
 * Es una de las lógicas del sistema anterior que no se habían portado, y no es
 * menor: de 3 653 planes vivos en los datos reales, **3 297 se cerraron así**.
 * Nadie los cerró a mano. Aquí el cierre era manual, con lo cual el 90% de los
 * planes se habrían quedado abiertos para siempre y el listado de «pendientes»
 * no habría servido para nada.
 *
 * La regla es literalmente la de la v1 (`Plan#lock_plan_if_all_conditions_met`,
 * y la misma copiada en `PlanApproval#lock_plan`):
 *
 *     if date_end.present? && plan_approvals.where(is_required: true, is_approved: false).none?
 *       update_columns(is_locked: true, is_done: true)
 *
 * Dos condiciones, y sólo dos: **hay hora de fin** y **no queda ninguna
 * aprobación obligatoria sin firmar**. No mira si los formatos están
 * confirmados ni si firmó toda la cuadrilla, y se respeta tal cual: en obra el
 * documento lo cierra la firma del que autoriza, que es quien se hace
 * responsable de lo demás. Cambiar eso aquí desalinearía los 3 297 planes
 * migrados con los que se creen a partir de mañana.
 *
 * `update_columns` en Rails salta los callbacks. El equivalente aquí es
 * `updateQuietly()`: sin eventos, para no reentrar en la evaluación ni disparar
 * una auditoría por algo que decide el sistema, no una persona.
 */
class WorkPlanCompletionService
{
    /**
     * Cierra el plan si cumple las condiciones. Idempotente.
     *
     * @return bool si lo ha cerrado en esta llamada
     */
    public function evaluar(WorkPlan $plan): bool
    {
        if ($plan->is_done && $plan->is_closed) {
            return false;
        }

        if (! $this->puedeCerrarse($plan)) {
            return false;
        }

        $plan->updateQuietly(['is_closed' => true, 'is_done' => true]);

        return true;
    }

    /**
     * Las condiciones para cerrar.
     *
     * Las dos de la v1 —hora de fin y ninguna aprobación obligatoria pendiente—
     * más una tercera que allí no hacía falta escribir: **que el plan tenga al
     * menos un trabajador y un formato**.
     *
     * En la v1 eso estaba garantizado río arriba. El plan se creaba de un solo
     * envío con sus trabajadores y sus formatos dentro, y
     * `must_have_at_least_one_document_and_worker` no dejaba guardarlo vacío:
     * «Debe tener al menos 1 documento», «Debe tener al menos 1 trabajador».
     * Un plan vacío no llegaba a existir.
     *
     * Aquí el plan se crea primero y se arma después, desde la ficha. Poner esa
     * validación al crear haría imposible crear ninguno —no hay trabajadores
     * todavía—, así que se traslada al punto donde significa lo mismo: un plan
     * vacío no se convierte en documento cerrado. Ni un solo plan de los 3 297
     * cerrados en los datos reales cambia de resultado por esto.
     */
    public function puedeCerrarse(WorkPlan $plan): bool
    {
        if ($plan->date_end === null) {
            return false;
        }

        if (! $plan->people()->exists() || $plan->expectedFormTemplates()->isEmpty()) {
            return false;
        }

        return ! $plan->approvals()
            ->where('is_required', true)
            ->where('is_approved', false)
            ->exists();
    }

    /**
     * Lo que le falta para cerrarse, para poder decírselo a quien mira.
     *
     * @return list<string>
     */
    public function loQueFalta(WorkPlan $plan): array
    {
        $falta = [];

        if ($plan->date_end === null) {
            $falta[] = __('work_plans.close_needs_date_end');
        }

        if (! $plan->people()->exists()) {
            $falta[] = __('work_plans.close_needs_crew');
        }

        if ($plan->expectedFormTemplates()->isEmpty()) {
            $falta[] = __('work_plans.close_needs_forms');
        }

        $pendientes = $plan->approvals()
            ->where('is_required', true)
            ->where('is_approved', false)
            ->count();

        if ($pendientes > 0) {
            $falta[] = trans_choice('work_plans.close_needs_approvals', $pendientes, ['count' => $pendientes]);
        }

        return $falta;
    }
}
