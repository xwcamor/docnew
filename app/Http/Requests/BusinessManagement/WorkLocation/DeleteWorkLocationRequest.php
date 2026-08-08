<?php

namespace App\Http\Requests\BusinessManagement\WorkLocation;

use Illuminate\Foundation\Http\FormRequest;

class DeleteWorkLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Que la fila se pueda borrar (que nadie la use) lo decide el servicio:
        // aqui solo se exige el motivo.
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $workLocation = $this->route('workLocation');
        if (is_object($workLocation) && $workLocation->is_locked) {
            return false;
        }

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
            'deleted_description.required' => __('work_locations.deleted_description_required'),
            'deleted_description.min'      => __('work_locations.deleted_description_min'),
            'deleted_description.max'      => __('work_locations.deleted_description_max'),
        ];
    }
}
