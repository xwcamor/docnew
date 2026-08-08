<?php

namespace App\Http\Requests\BusinessManagement\Workstation;

use Illuminate\Foundation\Http\FormRequest;

class DeleteWorkstationRequest extends FormRequest
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
            'deleted_description.required' => __('workstations.deleted_description_required'),
            'deleted_description.min'      => __('workstations.deleted_description_min'),
            'deleted_description.max'      => __('workstations.deleted_description_max'),
        ];
    }
}
