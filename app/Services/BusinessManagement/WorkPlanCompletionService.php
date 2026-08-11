<?php

namespace App\Services\BusinessManagement;

use App\Models\WorkPlan;
use App\Services\FieldWork\FormFindingsService;

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

        // Un plan reabierto a mano NO se vuelve a cerrar solo.
        //
        // Sin esto, reabrir es un boton que no sirve: las condiciones se siguen
        // cumpliendo —por eso estaba cerrado— asi que la primera evaluacion que
        // pase, que es el propio guardado que viene detras, lo cierra otra vez.
        // Entras a corregir y te expulsa.
        //
        // La marca la quita «Dar por terminado», que es quien decide que ya se
        // acabo de corregir. Mientras tanto el plan se queda en curso y el
        // listado lo ensena como tal, que es la verdad: alguien esta trabajando
        // en el.
        if ($plan->reopened_at !== null) {
            return false;
        }

        if (! $this->puedeCerrarse($plan)) {
            return false;
        }

        $plan->updateQuietly(['is_closed' => true, 'is_done' => true]);

        return true;
    }

    /**
     * Vuelve a abrir un plan terminado, para poder corregirlo.
     *
     * Deja rastro de quien y cuando: un plan terminado es el documento que
     * acaba delante de un inspector, y que alguien lo haya reabierto despues es
     * justo lo que hay que poder explicar.
     */
    public function reabrir(WorkPlan $plan): void
    {
        if (! $plan->is_closed && ! $plan->is_done) {
            return;   // ya estaba abierto: no hay nada que reabrir
        }

        $plan->update([
            'is_closed'   => false,
            'is_done'     => false,
            'reopened_at' => now(),
            'reopened_by' => auth()->id(),
        ]);
    }

    /**
     * Levanta la suspension y vuelve a evaluar.
     *
     * Es la contraparte de `reabrir()`: dice «ya termine de corregir». Si
     * todavia falta algo no cierra —lo decide `loQueFalta()`, no este boton— y
     * se avisa de que falta, en vez de dejar el plan en un limbo silencioso.
     *
     * @throws \DomainException si el plan todavia no esta completo
     */
    public function darPorTerminado(WorkPlan $plan): void
    {
        $falta = $this->loQueFalta($plan);

        if ($falta !== []) {
            throw new \DomainException(__('work_plans.close_still_missing', [
                'fields' => implode(' · ', $falta),
            ]));
        }

        $plan->update(['reopened_at' => null, 'reopened_by' => null]);

        $this->evaluar($plan->refresh());
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
     *   3. **Todos** los trabajadores han firmado, y hay un representante.
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

        // Y alguien que responda por la cuadrilla. Antes esto se colaba por la
        // via de las aprobaciones —habia una regla de rol `worker` obligatoria—
        // asi que al sacarlo del flujo habria dejado de exigirse sin que nadie
        // lo notara: el plan cerraria con cuadrilla y sin responsable.
        if ($trabajadores > 0 && $plan->crew_representative_person_id === null) {
            $falta[] = __('work_plans.close_needs_representative');
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

        // Lo que salio mal y sigue sin arreglarse.
        //
        // Decision del dueno del producto: un plan no se da por terminado con
        // una observacion viva. Pero la regla NO es «cero observaciones» —eso
        // seria una trampa: el dia que encuentras un arnes roto ese plan no
        // cerraria nunca, ni cambiando el arnes. La v1 cerraba igual con
        // observaciones y asi se cerraron 3 297 de 3 653 planes.
        //
        // Lo que bloquea es la observacion **cuya correccion no esta
        // verificada**. Encontrar un problema no atrapa el plan; no arreglarlo,
        // si. Para eso existe el campo «verificacion de la correccion», que ya
        // venia de la v1 y hasta ahora no decidia nada.
        $sinCorregir = $this->observacionesSinVerificar($plan);

        if ($sinCorregir > 0) {
            $falta[] = trans_choice('work_plans.close_needs_corrections', $sinCorregir, ['count' => $sinCorregir]);
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

    /**
     * Cuantas filas salieron no conformes y no dicen que se hayan corregido.
     *
     * Se mira fila a fila y no el contador `nonconformities` de la entrega,
     * porque la correccion tambien es por fila: en un EPP la lleva el
     * trabajador al que le falto algo, y en un IHM la herramienta. Un formato
     * con dos observaciones puede tener una verificada y la otra no.
     *
     * Cuenta como verificada cualquier cosa escrita en el campo: es texto libre
     * —«Se entrega guante nuevo, verificado por el supervisor»— y quien lo
     * escribe esta afirmando que lo comprobo. Exigir un formato concreto aqui
     * seria inventarse una regla que en obra nadie sigue.
     */
    protected function observacionesSinVerificar(WorkPlan $plan): int
    {
        $entregas = $plan->submissions()->with('answers')->get();

        $sinVerificar = 0;

        foreach ($entregas as $entrega) {
            foreach ($entrega->answers as $respuesta) {
                $fila = $respuesta->value_json;

                if (! is_array($fila)) {
                    continue;
                }

                if (! $this->filaNoConforme($fila)) {
                    continue;
                }

                if (blank($fila['correction_verification'] ?? null)) {
                    $sinVerificar++;
                }
            }
        }

        return $sinVerificar;
    }

    /**
     * Si esta fila de un checklist salio no conforme.
     *
     * La regla de que respuesta es mala vive en un solo sitio —
     * `FormFindingsService::tono()`— y aqui se usa esa, no una copia.
     */
    protected function filaNoConforme(array $fila): bool
    {
        $items = $fila['items'] ?? null;

        if (! is_array($items)) {
            return false;
        }

        $findings = app(\App\Services\FieldWork\FormFindingsService::class);

        foreach ($items as $item) {
            if (is_array($item) && $findings->tono($item['answer'] ?? null) === FormFindingsService::MALA) {
                return true;
            }
        }

        return false;
    }
}
