<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormSubmission extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['slug', 'work_plan_id', 'form_template_id', 'template_version',
                           'status', 'observations', 'submitted_by', 'submitted_at',
                           'tenant_id', 'created_by', 'deleted_by', 'deleted_description'];
    protected $casts = ['submitted_at' => 'datetime'];

    public function workPlan() { return $this->belongsTo(WorkPlan::class); }
    public function formTemplate() { return $this->belongsTo(FormTemplate::class); }
    public function answers() { return $this->hasMany(FormAnswer::class); }
    public function attachments() { return $this->hasMany(FormAttachment::class); }
    public function signatureEvents() { return $this->morphMany(SignatureEvent::class, 'signable'); }
}
