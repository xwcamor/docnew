<?php

namespace App\Http\Requests\BusinessManagement\ApprovalRule\Concerns;

use App\Models\ApproverRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Lo que hace válida a una regla del flujo, en un solo sitio para que crear y
 * editar no se separen con el tiempo.
 *
 * Dos comprobaciones son de dominio y no de formato:
 *
 *  - el rol que firma tiene que existir y estar activo en el catálogo. Una
 *    regla que nombra un código muerto exige una firma que nadie puede dar;
 *  - el mismo rol no firma dos veces el mismo flujo. `WorkPlanSetupService`
 *    ya lo rechaza al armar el plan, pero rechazarlo aquí evita crear una
 *    regla que solo va a fallar el día que alguien abra un plan.
 */
trait ValidatesApprovalRule
{
    protected function prepareForValidation(): void
    {
        // Un tipo de trabajo vacío significa «todos los tipos», no cero.
        if ($this->input('work_type_id') === '' || $this->input('work_type_id') === 0) {
            $this->merge(['work_type_id' => null]);
        }
    }

    protected function ruleRules(?int $ignoreId = null): array
    {
        return [
            'country_id'   => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],

            // En nulo la regla vale para todos los tipos: es el caso por
            // defecto y el que hereda cualquier tipo sin reglas propias.
            'work_type_id' => ['nullable', 'integer', Rule::exists('work_types', 'id')->whereNull('deleted_at')],

            'approver_role' => [
                'required', 'string', 'max:30',
                function ($attribute, $value, $fail) {
                    if (! array_key_exists($value, ApproverRole::opciones())) {
                        $fail(__('approval_rules.approver_role_unknown'));
                    }
                },
                function ($attribute, $value, $fail) use ($ignoreId) {
                    $repetida = DB::table('approval_rules')
                        ->whereNull('deleted_at')
                        ->where('country_id', (int) $this->input('country_id'))
                        ->where('approver_role', $value)
                        ->when(
                            $this->input('work_type_id') === null,
                            fn ($q) => $q->whereNull('work_type_id'),
                            fn ($q) => $q->where('work_type_id', (int) $this->input('work_type_id')),
                        )
                        ->whereRaw('coalesce(tenant_id, 0) = ?', [(int) (auth()->user()?->tenant_id ?? 0)])
                        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();

                    if ($repetida) {
                        $fail(__('approval_rules.duplicate_role_in_flow'));
                    }
                },
            ],

            // El nivel ordena el flujo; con `docufiz.sequential_approvals`
            // encendido además lo obliga. Un tope bajo a propósito: si un flujo
            // necesita más de 20 firmas, el problema no es el formulario.
            'priority_level' => ['required', 'integer', 'min:1', 'max:20'],

            'is_required' => ['sometimes', 'boolean'],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
