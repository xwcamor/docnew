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
                           'is_required', 'is_approved'];
    protected $casts = ['is_required' => 'boolean', 'is_approved' => 'boolean'];

    public function workPlan() { return $this->belongsTo(WorkPlan::class); }
    public function approvalRule() { return $this->belongsTo(ApprovalRule::class); }
    public function person() { return $this->belongsTo(Person::class); }
    public function signatureEvents() { return $this->morphMany(SignatureEvent::class, 'signable'); }
}
