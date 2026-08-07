<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormAnswer extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['form_submission_id', 'form_field_id', 'row_index',
                           'value_text', 'value_number', 'value_datetime', 'value_boolean', 'value_json'];
    protected $casts = ['value_number' => 'float', 'value_datetime' => 'datetime',
                        'value_boolean' => 'boolean', 'value_json' => 'array'];

    public function submission() { return $this->belongsTo(FormSubmission::class, 'form_submission_id'); }
    public function field() { return $this->belongsTo(FormField::class, 'form_field_id'); }
}
