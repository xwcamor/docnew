<?php

namespace App\Http\Requests\BusinessManagement\WorkPlan;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveWorkPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work_plans.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'       => 'required|array|min:1|max:500',
            'ids.*'     => 'integer',
            // El parametro se llama is_active porque lo emite el bulk compartido;
            // el service lo aplica sobre is_done, que es el estado de un plan.
            'is_active' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'       => __('global.bulk_no_selection'),
            'is_active.required' => __('work_plans.is_done_required'),
        ];
    }
}
