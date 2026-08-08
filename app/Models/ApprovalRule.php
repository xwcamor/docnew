<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Que firmas exige un plan antes de darse por aprobado.
 *
 * Es configuracion, no codigo: anadir una cuarta aprobacion es una fila mas.
 *
 *  - `work_type_id` en nulo → la regla vale para todos los tipos de trabajo.
 *    Con un tipo puesto, solo para ese: una maniobra de izaje puede exigir
 *    firmas que un mantenimiento estandar no necesita.
 *  - `tenant_id` en nulo → vale para todos los workspaces.
 *  - `priority_level` ordena el flujo, y si el workspace lo pide, tambien lo
 *    **obliga**: ver el ajuste `docufiz.sequential_approvals`.
 *  - `is_required` distingue la firma que no se puede saltar de la que si.
 */
class ApprovalRule extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;

    protected $fillable = ['slug', 'country_id', 'work_type_id', 'approver_role', 'priority_level',
                           'is_required', 'is_active', 'legacy_id', 'tenant_id', 'created_by',
                           'deleted_by', 'deleted_description'];
    protected $casts = ['is_required' => 'boolean', 'is_active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function approvals() { return $this->hasMany(WorkPlanApproval::class); }
    public function country() { return $this->belongsTo(Country::class); }
    public function workType() { return $this->belongsTo(WorkType::class); }

    public function role()
    {
        return $this->belongsTo(ApproverRole::class, 'approver_role', 'code');
    }

    /**
     * Las reglas que aplican a un plan, de la mas especifica a la general.
     *
     * Si hay reglas para su tipo de trabajo, mandan esas. Si no, las del pais
     * sin tipo. Asi acotar un tipo de trabajo no obliga a repetir el flujo
     * general en cada uno.
     */
    public static function paraPlan(WorkPlan $plan)
    {
        $base = static::query()
            ->where('is_active', true)
            ->where('country_id', $plan->country_id)
            ->when($plan->tenant_id, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('tenant_id')->orWhere('tenant_id', $plan->tenant_id)));

        $propias = (clone $base)->where('work_type_id', $plan->work_type_id)
            ->orderBy('priority_level')->get();

        return $propias->isNotEmpty()
            ? $propias
            : (clone $base)->whereNull('work_type_id')->orderBy('priority_level')->get();
    }
}
