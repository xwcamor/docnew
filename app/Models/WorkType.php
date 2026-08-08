<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * WorkType — la clase de maniobra («Estandar», «Izaje») y, con ella, los
 * papeles que esa maniobra obliga a llenar.
 *
 * No es un catálogo decorativo. El pivote `work_type_form_templates.is_required`
 * es lo que impide que un trabajo en altura salga sin AST porque alguien iba con
 * prisa: `WorkPlanSetupService::removeFormTemplate()` lo consulta y se niega a
 * quitar del plan un formato que el tipo marca como obligatorio. El mensaje que
 * el supervisor ve en ese momento —«cámbialo en el tipo de trabajo»— apunta
 * justo a esta ficha.
 *
 * El pivote se lee EN VIVO, no se copia al plan al crearlo (ver la migración de
 * `work_plan_form_templates`). Por eso tocarlo alcanza a los planes abiertos: es
 * lo que se quiere cuando se corrige un estándar de seguridad, y es también lo
 * que hay que avisar antes de guardar.
 */
class WorkType extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = [
        'slug', 'country_id', 'code', 'is_active', 'legacy_id',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * La URL lleva el slug y no el id, como el resto de los módulos: un id
     * correlativo en la barra de direcciones dice cuántos registros hay.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    public function formTemplates()
    {
        return $this->belongsToMany(FormTemplate::class, 'work_type_form_templates')
            ->withPivot('is_required');
    }

    public function workPlans(): HasMany
    {
        return $this->hasMany(WorkPlan::class);
    }

    /**
     * Los planes que un cambio en los formatos alcanza de verdad.
     *
     * Un plan cerrado es de sólo lectura y su documentación ya está firmada: si
     * el estándar cambia hoy, no se le puede pedir un formato de ayer. Sólo
     * cuentan los abiertos, y son los que hay que decir en voz alta antes de
     * guardar.
     */
    public function scopeConPlanesAbiertos($q)
    {
        return $q->withCount(['workPlans as open_plans_count' => fn ($w) => $w->where('is_closed', false)]);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
