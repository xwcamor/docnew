<?php

namespace App\Http\Requests\BusinessManagement\WorkPlan;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateWorkPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work_plans.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('work_plans.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // El código del plan NO se edita en lote: identifica el registro y
            // es la referencia contra el sistema v1. Solo la orden de servicio
            // y el avance se corrigen en masa.
            'changes.*.num_os'    => 'sometimes|nullable|string|max:255',
            'changes.*.is_done'   => 'sometimes|nullable|boolean',
        ];
    }
}
