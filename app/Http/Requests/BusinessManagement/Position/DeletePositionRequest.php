<?php

namespace App\Http\Requests\BusinessManagement\Position;

use Illuminate\Foundation\Http\FormRequest;

class DeletePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Que la fila se pueda borrar (que nadie la use) lo decide el servicio:
        // aqui solo se exige el motivo.
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $position = $this->route('position');
        if (is_object($position) && $position->is_locked) {
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
            'deleted_description.required' => __('positions.deleted_description_required'),
            'deleted_description.min'      => __('positions.deleted_description_min'),
            'deleted_description.max'      => __('positions.deleted_description_max'),
        ];
    }
}
