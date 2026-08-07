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
                           'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
                           'legacy_id', 'legacy_table'];
    protected $casts = ['submitted_at' => 'datetime'];

    /**
     * Las rutas se enlazan por slug, como en todo el resto del proyecto.
     *
     * No es cosmetico. El id es un numero correlativo, asi que con el enlace
     * por id bastaba con saber contar para confirmar la entrega de al lado. Y
     * la pantalla de llenado siempre mando el slug, de modo que sin esta linea
     * no funcionaban ni guardar respuestas, ni adjuntar la foto del papel, ni
     * cerrar el formato: el flujo entero del trabajo en campo.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function workPlan() { return $this->belongsTo(WorkPlan::class); }
    public function formTemplate() { return $this->belongsTo(FormTemplate::class); }
    public function answers() { return $this->hasMany(FormAnswer::class); }
    public function attachments() { return $this->hasMany(FormAttachment::class); }
    public function signatureEvents() { return $this->morphMany(SignatureEvent::class, 'signable'); }
}
