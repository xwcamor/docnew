<?php

namespace App\Http\Requests\BusinessManagement\WorkArea;

use Illuminate\Foundation\Http\FormRequest;

class DeleteWorkAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Que la fila se pueda borrar (que nadie la use) lo decide el servicio:
        // aqui solo se exige el motivo.
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
            'deleted_description.required' => __('work_areas.deleted_description_required'),
            'deleted_description.min'      => __('work_areas.deleted_description_min'),
            'deleted_description.max'      => __('work_areas.deleted_description_max'),
        ];
    }
}
