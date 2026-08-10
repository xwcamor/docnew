<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormSection extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['form_template_id', 'name_es', 'name_en', 'position'];

    /**
     * El titulo de la seccion en el idioma en curso.
     *
     * Que venga vacio no es raro: el motor nacio sin estas columnas y una
     * seccion creada desde la pantalla puede no llevar titulo. Cuando pasa se
     * devuelve cadena vacia y no un «Seccion 2» inventado, porque el formato de
     * papel tampoco numera sus bloques: el que tiene titulo lo lleva escrito.
     */
    public function getLabelAttribute(): string
    {
        $preferido = app()->getLocale() === 'en' ? $this->name_en : $this->name_es;

        return $preferido ?? $this->name_es ?? '';
    }

    public function formTemplate() { return $this->belongsTo(FormTemplate::class); }
    public function fields() { return $this->hasMany(FormField::class)->orderBy('position'); }
}
