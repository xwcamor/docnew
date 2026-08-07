<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkPlan extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'country_id', 'company_id', 'work_type_id', 'work_location_id',
        'workstation_id', 'work_area_id', 'user_id', 'code', 'num_os', 'description',
        'date_start', 'date_end', 'is_locked', 'is_done', 'legacy_id',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = ['is_locked' => 'boolean', 'is_done' => 'boolean',
                        'date_start' => 'date', 'date_end' => 'date'];

    public function company() { return $this->belongsTo(Company::class); }
    public function workType() { return $this->belongsTo(WorkType::class); }
    public function workLocation() { return $this->belongsTo(WorkLocation::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function people() { return $this->hasMany(WorkPlanPerson::class); }
    public function approvals() { return $this->hasMany(WorkPlanApproval::class); }
    public function submissions() { return $this->hasMany(FormSubmission::class); }

    /** El plan esta completo cuando todo formato exigido esta confirmado y toda
     *  aprobacion obligatoria esta aprobada. Lo decide el servidor, nunca el formulario. */
    public function isComplete(): bool
    {
        $faltanFormatos = $this->submissions()->where('status', '!=', 'confirmed')->exists();
        $faltanFirmas   = $this->approvals()->where('is_required', true)->where('is_approved', false)->exists();

        return ! $faltanFormatos && ! $faltanFirmas;
    }
}
