<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormSection extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['form_template_id', 'position'];

    public function formTemplate() { return $this->belongsTo(FormTemplate::class); }
    public function fields() { return $this->hasMany(FormField::class)->orderBy('position'); }
}
