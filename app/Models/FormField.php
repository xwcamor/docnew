<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['form_section_id', 'code', 'field_type', 'is_required',
                           'position', 'config', 'visibility_rule'];
    protected $casts = ['is_required' => 'boolean', 'config' => 'array', 'visibility_rule' => 'array'];

    /** Tipos simples y tipos compuestos que reproducen los formatos historicos. */
    public const TIPOS = [
        'text', 'textarea', 'number', 'date', 'time', 'select', 'multiselect',
        'checkbox', 'radio', 'table', 'photo', 'file', 'signature',
        'person_checklist', 'tool_checklist', 'risk_matrix', 'question_bank',
    ];

    public function section() { return $this->belongsTo(FormSection::class, 'form_section_id'); }
    public function answers() { return $this->hasMany(FormAnswer::class); }
}
