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
     * La regla del sistema anterior, y la que rige siempre: **primero responde
     * la cuadrilla**.
     *
     * Literal de `_show_form_approvals.html.erb`:
     *
     *     required_workers_pending = @list_plan_approvals.select { |p|
     *       p.approver_type == "Worker" && p.approval_rule.is_required && !p.is_approved }
     *     next if approver_type != "worker" && !all_required_workers_signed
     *
     * Es decir: mientras no hubiera alguien de la cuadrilla que respondiera por
     * el trabajo, las demas aprobaciones ni se enseñaban. El que ejecuta declara
     * primero lo que va a hacer; el supervisor autoriza sobre esa declaracion.
     * Autorizar antes es firmar en blanco.
     *
     * Antes esto se miraba contra una aprobacion de rol `worker`. Ese rol ya no
     * existe —el representante es una columna del plan, no una aprobacion— asi
     * que la misma regla se comprueba donde ahora vive el dato. Ojo con la
     * tentacion de mirarlo contra `work_plan_people`: esas son firmas de
     * asistencia a la charla y no gobiernan el flujo de autorizacion, que es
     * justo el error que ya se cometio una vez.
     */
    public function faltaElRepresentante(): bool
    {
        return $this->workPlan?->crew_representative_person_id === null;
    }
}