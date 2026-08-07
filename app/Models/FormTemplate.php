<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormTemplate extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['slug', 'country_id', 'code', 'kind', 'status', 'version',
                           'requires_signature', 'pdf_template', 'published_at', 'is_active',
                           'tenant_id', 'created_by', 'deleted_by', 'deleted_description'];
    protected $casts = ['requires_signature' => 'boolean', 'is_active' => 'boolean',
                        'published_at' => 'datetime'];

    /** structured: campos propios · upload_only: solo se fotografia el papel · hybrid: ambos. */
    public const STRUCTURED  = 'structured';
    public const UPLOAD_ONLY = 'upload_only';
    public const HYBRID      = 'hybrid';

    public function sections() { return $this->hasMany(FormSection::class)->orderBy('position'); }
    public function fields() { return $this->hasManyThrough(FormField::class, FormSection::class); }
    public function submissions() { return $this->hasMany(FormSubmission::class); }
    public function workTypes() { return $this->belongsToMany(WorkType::class, 'work_type_form_templates'); }

    public function scopePublished($q) { return $q->where('status', 'published'); }
}
