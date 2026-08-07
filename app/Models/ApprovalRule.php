<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalRule extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['slug', 'country_id', 'approver_role', 'priority_level',
                           'is_required', 'is_active', 'tenant_id', 'created_by',
                           'deleted_by', 'deleted_description'];
    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean'];

    public function approvals() { return $this->hasMany(WorkPlanApproval::class); }
}
