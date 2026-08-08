<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Diferencia entre los formatos que exige el tipo de trabajo y los que exige
 * ESTE plan. No es la lista de formatos del plan: es lo que se le añadió o se
 * le quitó al estándar. Ver la migración para el porqué.
 */
class WorkPlanFormTemplate extends Model
{
    use HasFactory;
    use Auditable;

    protected $fillable = ['slug', 'work_plan_id', 'form_template_id', 'is_required', 'is_included'];
    protected $casts = ['is_required' => 'boolean', 'is_included' => 'boolean'];

    public function workPlan() { return $this->belongsTo(WorkPlan::class); }
    public function formTemplate() { return $this->belongsTo(FormTemplate::class); }
}
