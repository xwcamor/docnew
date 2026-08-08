<?php

namespace App\Services\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanFormTemplate;
use App\Models\WorkPlanPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WorkPlanSetupService — armar el plan del día: su cuadrilla, los formatos que
 * exige y quién tiene que aprobarlo.
 *
 * Está separado de WorkPlanService porque son cosas distintas: aquel edita el
 * plan (fechas, sede, descripción), este monta lo que cuelga del plan. Y sobre
 * todo porque aquí vive una regla que no admite excepciones: **nada de lo que
 * ya se firmó o se llenó se puede quitar**. Un plan es un documento de
 * seguridad; borrar de él a alguien que puso su cara delante de la cámara es
 * destruir la evidencia de que estuvo en obra ese día.
 *
 * Cuando una operación se rechaza lanza DomainException con el mensaje ya
 * traducido: el controller lo pasa a un flash y el supervisor entiende por qué,
 * en vez de ver un botón que no hace nada.
 */
class WorkPlanSetupService
{
    // ── Cuadrilla ────────────────────────────────────────────────────────────

    public function addPerson(WorkPlan $plan, Person $persona): WorkPlanPerson
    {
        $this->assertOpen($plan);

        // El índice único ya lo impediría, pero saltando con un error de base
        // de datos. Aquí se dice en castellano lo que pasó.
        if ($plan->people()->where('person_id', $persona->id)->exists()) {
            throw new \DomainException(__('work_plans.crew_already_assigned', ['name' => $persona->full_name]));
        }

        return $plan->people()->create([
            'slug'      => $this->slug(WorkPlanPerson::class),
            'person_id' => $persona->id,
        ]);
    }

    /**
     * Quita a alguien de la cuadrilla. Si ya firmó, no: su firma es la prueba
     * de que recibió la charla de seguridad de ese día.
     */
    public function removePerson(WorkPlan $plan, WorkPlanPerson $asignado): void
    {
        $this->assertOpen($plan);
        $this->assertBelongs($plan, $asignado->work_plan_id);

        if ($asignado->is_approved || $asignado->signatureEvents()->exists()) {
            throw new \DomainException(__('work_plans.crew_signed_cannot_remove', [
                'name' => $asignado->person?->full_name ?? '',
            ]));
        }

        $asignado->delete();
    }

    // ── Formatos ─────────────────────────────────────────────────────────────

    /**
     * Añade un formato extra a ESTE plan. Si el formato ya venía del tipo de
     * trabajo y solo se le había quitado, esto deshace la exclusión.
     */
    public function addFormTemplate(WorkPlan $plan, FormTemplate $plantilla, bool $obligatorio = true): WorkPlanFormTemplate
    {
        $this->assertOpen($plan);

        // Una plantilla en borrador no se puede llenar: ofrecerla sería mandar
        // al usuario de campo a una pantalla que va a reventar al abrirla.
        if ($plantilla->status !== 'published') {
            throw new \DomainException(__('work_plans.form_not_published', ['code' => $plantilla->code]));
        }

        $override = $plan->formTemplateOverrides()->firstOrNew(['form_template_id' => $plantilla->id]);

        if ($override->exists && $override->is_included) {
            throw new \DomainException(__('work_plans.form_already_required', ['code' => $plantilla->code]));
        }

        $override->fill([
            'slug'        => $override->slug ?: $this->slug(WorkPlanFormTemplate::class),
            'is_included' => true,
            'is_required' => $obligatorio,
        ])->save();

        return $override;
    }

    /**
     * Quita un formato de este plan. Si la entrega tiene respuestas, adjuntos o
     * firmas, no se toca: eso es el formato lleno, no una casilla de más.
     *
     * Si la entrega está vacía (se abrió y no se llenó nada) se borra junto con
     * la exclusión — dejarla ahí haría que el plan nunca se pudiera dar por
     * terminado, porque `isComplete()` cuenta toda entrega no confirmada.
     */
    public function removeFormTemplate(WorkPlan $plan, FormTemplate $plantilla): void
    {
        $this->assertOpen($plan);

        // Un formato que el tipo de trabajo marca como obligatorio no se quita.
        //
        // Es la razon de ser de `work_type_documents.is_required`: el tipo de
        // trabajo decide que papeles exige esa clase de maniobra, y eso es lo
        // que impide que un trabajo en altura salga sin AST porque alguien iba
        // con prisa. Quien opine que ese formato sobra tiene que cambiar el
        // tipo de trabajo, que afecta a todos los planes y deja rastro — no
        // saltarselo en el plan de un martes.
        //
        // Los opcionales del tipo (`is_required = 0`) y los añadidos a mano si
        // se quitan: para eso estan.
        if ($this->esObligatorioDelTipo($plan, $plantilla)) {
            throw new \DomainException(__('work_plans.form_required_by_work_type', [
                'code' => $plantilla->code,
                'type' => $plan->workType?->code ?? '—',
            ]));
        }

        $entrega = $plan->submissions()->where('form_template_id', $plantilla->id)->first();

        if ($entrega && $this->entregaTieneContenido($entrega)) {
            throw new \DomainException(__('work_plans.form_filled_cannot_remove', ['code' => $plantilla->code]));
        }

        DB::transaction(function () use ($plan, $plantilla, $entrega) {
            $entrega?->forceDelete();

            $override = $plan->formTemplateOverrides()->firstOrNew(['form_template_id' => $plantilla->id]);
            $override->fill([
                'slug'        => $override->slug ?: $this->slug(WorkPlanFormTemplate::class),
                'is_included' => false,
                'is_required' => false,
            ])->save();
        });
    }

    /**
     * Si el tipo de trabajo del plan exige este formato.
     *
     * Se mira el pivote `form_template_work_type.is_required` —el
     * `work_type_documents` del sistema anterior—, no el `is_required` que
     * pueda tener el plan: el del plan es un ajuste local y no puede levantar
     * una obligacion que viene del catalogo.
     */
    public function esObligatorioDelTipo(WorkPlan $plan, FormTemplate $plantilla): bool
    {
        $pivote = $plan->workType?->formTemplates()
            ->where('form_templates.id', $plantilla->id)
            ->first()?->pivot;

        return (bool) ($pivote?->is_required ?? false);
    }

    /** Una entrega "tiene contenido" si alguien la trabajó: respuestas, foto del papel, firma o cierre. */
    private function entregaTieneContenido(\App\Models\FormSubmission $entrega): bool
    {
        return $entrega->status === 'confirmed'
            || $entrega->answers()->exists()
            || $entrega->attachments()->exists()
            || $entrega->signatureEvents()->exists();
    }

    // ── Aprobaciones ─────────────────────────────────────────────────────────

    public function addApproval(WorkPlan $plan, ApprovalRule $regla, ?Person $persona, ?bool $obligatoria = null): WorkPlanApproval
    {
        $this->assertOpen($plan);

        // Una aprobación por regla: la regla ES el rol que firma (supervisor,
        // HSE…), y el mismo rol no aprueba dos veces el mismo plan.
        if ($plan->approvals()->where('approval_rule_id', $regla->id)->exists()) {
            throw new \DomainException(__('work_plans.approval_role_taken', ['role' => __('work_plans.approver_role.' . $regla->approver_role)]));
        }

        return $plan->approvals()->create([
            'slug'             => $this->slug(WorkPlanApproval::class),
            'approval_rule_id' => $regla->id,
            'person_id'        => $persona?->id,
            'is_required'      => $obligatoria ?? (bool) $regla->is_required,
        ]);
    }

    /**
     * Le pone nombre a una aprobación pendiente.
     *
     * Es lo único que se hace con una aprobación desde la ficha, y es lo que
     * hacía el sistema anterior: la fila ya existe —la creó la regla del país—
     * y lo que falta es **quién** la firma. Se escribe el documento, si aparece
     * se asigna, y a firmar.
     *
     * Una aprobación **no se borra**. Pertenece al flujo, no al plan: si el
     * país exige la firma de un supervisor HSE, quitar la fila no quita la
     * obligación, sólo la esconde. Y una ya firmada menos todavía — la firma es
     * la prueba de que alguien se hizo responsable.
     */
    public function assignApprover(WorkPlan $plan, WorkPlanApproval $aprobacion, Person $persona): WorkPlanApproval
    {
        $this->assertOpen($plan);
        $this->assertBelongs($plan, $aprobacion->work_plan_id);

        if ($aprobacion->is_approved || $aprobacion->signatureEvents()->exists()) {
            throw new \DomainException(__('work_plans.approval_signed_cannot_reassign'));
        }

        $rol = $aprobacion->approvalRule?->approver_role;

        // El ejecutante sale **de los trabajadores de este plan**.
        //
        // Es quien está en la obra y responde por lo que se va a hacer, así que
        // no puede ser alguien que no salió a trabajar ese día. Ni el sistema
        // anterior ni la primera versión de esto lo comprobaban: los dos
        // buscaban por documento en las 231 personas del padrón, y se podía
        // poner como responsable de la obra a quien no estuvo en ella.
        $comoTrabajador = null;

        if ($rol === ApproverRole::WORKER) {
            $comoTrabajador = $plan->people()->where('person_id', $persona->id)->first();

            if (! $comoTrabajador) {
                throw new \DomainException(__('work_plans.approval_not_in_crew', [
                    'name' => $persona->list_name,
                ]));
            }

            // Y tiene que haber firmado ya, como trabajador.
            //
            // Designar ejecutante a quien todavía no ha firmado deja la
            // aprobación esperando una firma que esa persona va a dar igual en
            // la otra columna: acabaría firmando dos veces el mismo día, el
            // mismo plan, delante de la misma cámara, y la segunda no prueba
            // nada que no probara la primera.
            //
            // El ejecutante se elige ENTRE LOS QUE YA FIRMARON, y esa firma es
            // la que vale — la aprobación se da por hecha abajo.
            if (! $this->firmoComoTrabajador($comoTrabajador)) {
                throw new \DomainException(__('work_plans.approval_worker_must_sign_first', [
                    'name' => $persona->list_name,
                ]));
            }
        } elseif ($rol && ! $persona->roles()->where('role', $rol)->where('is_active', true)->exists()) {
            // Supervisor y HSE no están en la cuadrilla, así que lo que se
            // exige es el rol. En el sistema anterior el selector iba contra
            // tres endpoints distintos según el tipo, así que la lista ya venía
            // filtrada; aquí se ofrecía a cualquiera y se podía poner al
            // ayudante a firmar como supervisor de seguridad. La comprobación
            // vive en el servicio porque el filtro del buscador se salta con
            // una petición hecha a mano.
            throw new \DomainException(__('work_plans.approval_wrong_role', [
                'name' => $persona->list_name,
                'role' => __('work_plans.approver_role.' . $rol),
            ]));
        }

        // La misma persona no firma dos roles del mismo plan: seria la misma
        // firma contada dos veces, y el plan parecería mas aprobado de lo que está.
        $repetida = $plan->approvals()
            ->where('person_id', $persona->id)
            ->where('id', '!=', $aprobacion->id)
            ->exists();

        if ($repetida) {
            throw new \DomainException(__('work_plans.approval_person_taken', ['name' => $persona->list_name]));
        }

        $aprobacion->update(['person_id' => $persona->id]);

        // El ejecutante ya firmó: la aprobación queda dada con esa firma.
        //
        // No se crea un evento nuevo. La evidencia es la que ya existe —su foto
        // y su firma de trabajador de este mismo plan—, y duplicarla haría
        // parecer que hubo dos comprobaciones donde hubo una. La ficha lee la
        // hora de la firma del trabajador (`approvalsPayload`).
        if ($comoTrabajador) {
            $aprobacion->update(['is_approved' => true]);

            // Designar al ejecutante puede ser lo último que faltaba.
            app(WorkPlanCompletionService::class)->evaluar($plan);
        }

        return $aprobacion->refresh();
    }

    /** ¿Este trabajador ya firmó en este plan? */
    protected function firmoComoTrabajador(WorkPlanPerson $asignado): bool
    {
        return (bool) $asignado->is_approved || $asignado->signatureEvents()->exists();
    }

    /**
     * Propone los aprobadores por defecto al crear un plan.
     *
     * Las reglas (`approval_rules`) dicen qué roles tienen que firmar en ese
     * país y si su firma es obligatoria. Se crean SIN persona: quién firma como
     * supervisor cambia cada día y lo elige el que arma el plan. Lo que la
     * regla fija es que la firma hace falta, y eso es lo que no se puede
     * olvidar.
     *
     * @return int cuántas aprobaciones se propusieron
     */
    public function seedApprovalsFromRules(WorkPlan $plan): int
    {
        // Las reglas de su tipo de trabajo si las hay, y si no las del pais:
        // una maniobra de izaje puede exigir firmas que un mantenimiento
        // estandar no necesita. Ver ApprovalRule::paraPlan().
        $reglas = ApprovalRule::paraPlan($plan);

        $yaPuestas = $plan->approvals()->pluck('approval_rule_id')->all();
        $creadas = 0;

        foreach ($reglas as $regla) {
            if (in_array($regla->id, $yaPuestas, true)) {
                continue;
            }
            $plan->approvals()->create([
                'slug'             => $this->slug(WorkPlanApproval::class),
                'approval_rule_id' => $regla->id,
                'person_id'        => null,
                'is_required'      => (bool) $regla->is_required,
            ]);
            $creadas++;
        }

        return $creadas;
    }

    // ── Apoyo ────────────────────────────────────────────────────────────────

    /**
     * Un plan cerrado, terminado o con candado no se recompone. Es la misma
     * regla que impide editarlo, aplicada a lo que le cuelga: de poco sirve
     * blindar el formulario del plan si por la ficha se le puede sacar medio
     * equipo.
     */
    public function assertOpen(WorkPlan $plan): void
    {
        if (! $plan->isOpenForSetup()) {
            throw new \DomainException($plan->setupBlockedReason() ?? __('locks.cannot_edit_locked'));
        }
    }

    /** Nadie mueve la cuadrilla de un plan desde la ficha de otro. */
    private function assertBelongs(WorkPlan $plan, int $workPlanId): void
    {
        abort_if($workPlanId !== $plan->id, 404);
    }

    /** Slug de 22 al azar, único en su tabla — la convención de todo el proyecto. */
    private function slug(string $modelo): string
    {
        do {
            $slug = Str::random(22);
        } while ($modelo::where('slug', $slug)->exists());

        return $slug;
    }
}
