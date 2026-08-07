<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkPlanPerson extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['slug', 'work_plan_id', 'person_id', 'is_approved', 'legacy_id'];
    protected $casts = ['is_approved' => 'boolean'];

    public function workPlan() { return $this->belongsTo(WorkPlan::class); }
    public function person() { return $this->belongsTo(Person::class); }
    public function signatureEvents() { return $this->morphMany(SignatureEvent::class, 'signable'); }
}
