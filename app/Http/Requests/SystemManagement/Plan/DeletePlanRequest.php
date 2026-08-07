<?php

namespace App\Http\Requests\SystemManagement\Plan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Soft-delete con motivo obligatorio (patrón Regions). El motivo queda
 * en `deleted_description` y aparece en Trash + audit log.
 */
class DeletePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'deleted_description.required' => __('plans.deleted_description_required'),
            'deleted_description.min'      => __('plans.deleted_description_min'),
            'deleted_description.max'      => __('plans.deleted_description_max'),
        ];
    }
}
