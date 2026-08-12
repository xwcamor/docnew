<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormField extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['form_section_id', 'code', 'label_es', 'label_en', 'field_type',
                           'is_required', 'position', 'config', 'visibility_rule'];
    protected $casts = ['is_required' => 'boolean', 'config' => 'array', 'visibility_rule' => 'array'];

    /**
     * La etiqueta que se lee al lado del campo, en el idioma en curso.
     *
     * Antes de que existieran estas columnas la pantalla de llenado hacia
     * `config.label ?? humanizar(code)`. Lo segundo sale legible de chiripa
     * —los codigos se escribieron en castellano— pero `epp_por_trabajador` da
     * «Epp por trabajador», y en ingles no da nada. Se conserva entera esa
     * cadena de respaldo para que un campo creado antes de la columna, o desde
     * el editor sin rellenarla, se siga leyendo como se leia.
     */
    public function getLabelAttribute(): string
    {
        $preferido = app()->getLocale() === 'en' ? $this->label_en : $this->label_es;

        return $preferido
            ?? $this->label_es
            ?? ($this->config['label'] ?? null)
            ?? ucfirst(str_replace('_', ' ', (string) $this->code));
    }

    /**
     * Los tipos cuyo valor NO vive en `form_answers` sino en `form_attachments`.
     *
     * Estaba escrito en `FormSubmissionPdfService`, que era el unico que lo
     * sabia, y `faltantesDetallados()` no se habia enterado: un campo de foto
     * obligatorio se contaba como sin responder para siempre —porque nunca
     * tendra fila en `form_answers`— y el formato no se podia confirmar jamas.
     *
     * La firma entra aqui por lo mismo que la foto: es una imagen. El trazo
     * llega del lienzo como PNG y se guarda igual que un archivo cualquiera.
     */
    public const CON_ARCHIVO = ['photo', 'file', 'signature'];

    /** Tipos simples y tipos compuestos que reproducen los formatos historicos. */
    public const TIPOS = [
        'text', 'textarea', 'number', 'date', 'time', 'select', 'multiselect',
        'checkbox', 'radio', 'table', 'photo', 'file', 'signature',
        'person_checklist', 'tool_checklist', 'risk_matrix', 'question_bank',
    ];

    public function section() { return $this->belongsTo(FormSection::class, 'form_section_id'); }
    public function answers() { return $this->hasMany(FormAnswer::class); }
}
