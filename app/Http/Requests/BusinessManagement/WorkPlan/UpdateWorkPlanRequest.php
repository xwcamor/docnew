<?php

namespace App\Http\Requests\BusinessManagement\WorkPlan;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateWorkPlanRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'work_plans';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $workPlan = $this->route('workPlan');
        if (is_object($workPlan) && $workPlan->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $workPlan   = $this->route('workPlan');
        $workPlanId = is_object($workPlan) ? $workPlan->id : null;

        return [
            // Unicidad de name case + accent insensitive PER-TENANT, ignorando el
            // propio workPlan y soft-deleted. Se filtra por tenant_id para alinear con
            // el indice unico parcial (tenant_id, name) de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($workPlanId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('work_plans')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($workPlanId, fn ($qq) => $qq->where('id', '!=', $workPlanId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('work_plans.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($workPlanId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('work_plans')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($workPlanId, fn ($qq) => $qq->where('id', '!=', $workPlanId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('work_plans.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
