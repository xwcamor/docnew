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
}
