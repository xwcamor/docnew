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
     * Las condiciones para cerrar. **No falta nada del plan.**
     *
     * La v1 sólo miraba dos cosas —hora de fin y ninguna aprobación obligatoria
     * pendiente— y cerraba aunque quedaran formatos a medias o trabajadores sin
     * firmar. Aquí se exige el plan entero, por decisión del dueño del producto:
     *
     *   1. Hora de fin del trabajo.
     *   2. Al menos un trabajador y un formato.
     *   3. **Todos** los trabajadores han firmado.
     *   4. **Todos** los formatos exigidos están confirmados.
     *   5. Ninguna aprobación obligatoria sin firmar.
     *
     * Es más estricto que la v1 a propósito: un documento que va a acabar
     * delante de un inspector no se cierra a medias. Los 3 297 planes migrados
     * conservan el estado con el que llegaron —cerrados bajo la regla vieja— y
     * no se reabren; la regla nueva rige de aquí en adelante.
     *
     * Los puntos 3 y 4 no existían en la v1 en esta forma, pero sí su intención:
     * `must_have_at_least_one_document_and_worker` impedía guardar un plan
     * vacío. Allí el plan nacía completo de un solo envío; aquí se crea primero
     * y se arma después, así que la comprobación vive donde significa lo mismo.
     */
    public function puedeCerrarse(WorkPlan $plan): bool
    {
        return $this->loQueFalta($plan) === [];
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

        // Trabajadores: tiene que haber, y tienen que haber firmado todos. La
        // firma es la prueba de que la persona estuvo y recibió la charla; un
        // plan cerrado con firmas a medias no prueba nada.
        $trabajadores = $plan->people()->count();

        if ($trabajadores === 0) {
            $falta[] = __('work_plans.close_needs_crew');
        } else {
            $sinFirmar = $plan->people()->where('is_approved', false)->count();

            if ($sinFirmar > 0) {
                $falta[] = trans_choice('work_plans.close_needs_signatures', $sinFirmar, ['count' => $sinFirmar]);
            }
        }

        // Formatos: tiene que haber, y todos los que el plan exige tienen que
        // estar confirmados. Un AST en borrador no es un AST.
        $esperados = $plan->expectedFormTemplates();

        if ($esperados->isEmpty()) {
            $falta[] = __('work_plans.close_needs_forms');
        } else {
            $confirmados = $plan->submissions()
                ->where('status', 'confirmed')
                ->whereIn('form_template_id', $esperados->keys())
                ->count();

            $sinConfirmar = $esperados->count() - $confirmados;

            if ($sinConfirmar > 0) {
                $falta[] = trans_choice('work_plans.close_needs_forms_done', $sinConfirmar, ['count' => $sinConfirmar]);
            }
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
