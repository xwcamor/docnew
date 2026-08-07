<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workstation extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'work_location_id', 'name', 'is_active', 'legacy_id',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workLocation()
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
