<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkPlanApproval extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['slug', 'work_plan_id', 'approval_rule_id', 'person_id',
                           'is_required', 'is_approved', 'legacy_id'];
    protected $casts = ['is_required' => 'boolean', 'is_approved' => 'boolean'];

    public function workPlan() { return $this->belongsTo(WorkPlan::class); }
    public function approvalRule() { return $this->belongsTo(ApprovalRule::class); }
    public function person() { return $this->belongsTo(Person::class); }
    public function signatureEvents() { return $this->morphMany(SignatureEvent::class, 'signable'); }

    /**
     * Que aprobaciones **obligatorias** de nivel anterior siguen sin firmar.
     *
     * `priority_level` ordenaba la lista en pantalla y nada mas: el supervisor
     * HSE podia firmar antes que el trabajador. En un flujo secuencial eso vacia
     * de sentido la firma de arriba, porque se esta aprobando algo que aun no
     * ha aprobado quien tenia que ir primero.
     *
     * Las opcionales no frenan a nadie: para eso son opcionales.
     */
    public function aprobacionesPendientesAntes()
    {
        $nivel = $this->approvalRule?->priority_level;

        if ($nivel === null) {
            return collect();
        }

        return static::query()
            ->where('work_plan_id', $this->work_plan_id)
            ->where('is_required', true)
            ->where('is_approved', false)
            ->whereHas('approvalRule', fn ($q) => $q->where('priority_level', '<', $nivel))
            ->with('approvalRule.role')
            ->get();
    }

    /**
     * La regla del sistema anterior, y la que rige siempre: **primero firma el
     * ejecutante**.
     *
     * Literal de `_show_form_approvals.html.erb`:
     *
     *     required_workers_pending = @list_plan_approvals.select { |p|
     *       p.approver_type == "Worker" && p.approval_rule.is_required && !p.is_approved }
     *     next if approver_type != "worker" && !all_required_workers_signed
     *
     * Es decir: mientras quede una aprobación **de rol trabajador** obligatoria
     * sin firmar, las demás ni se enseñaban. El que ejecuta el trabajo declara
     * primero lo que va a hacer; el supervisor autoriza sobre esa declaración.
     * Autorizar antes es firmar en blanco.
     *
     * Yo lo había puesto contra la cuadrilla (`work_plan_people`), que es otra
     * cosa: ésas son firmas de asistencia a la charla y no gobiernan el flujo de
     * autorización. Y la comprobación del servidor sólo existía con
     * `docufiz.sequential_approvals` activo, que viene apagado — o sea que en la
     * práctica no había ninguna: la pantalla bloqueaba y el servidor no.
     *
     * @return \Illuminate\Support\Collection<int, static>
     */
    public function ejecutantesPendientes()
    {
        // El propio ejecutante no se espera a sí mismo.
        if ($this->approvalRule?->approver_role === ApproverRole::WORKER) {
            return collect();
        }

        return static::query()
            ->where('work_plan_id', $this->work_plan_id)
            ->where('is_required', true)
            ->where('is_approved', false)
            ->whereHas('approvalRule', fn ($q) => $q->where('approver_role', ApproverRole::WORKER))
            ->with('approvalRule.role')
            ->get();
    }
}
