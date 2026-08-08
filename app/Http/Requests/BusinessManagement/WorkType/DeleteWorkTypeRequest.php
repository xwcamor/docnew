<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use Illuminate\Foundation\Http\FormRequest;

class DeleteWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $workType = $this->route('workType');
        if (is_object($workType) && $workType->is_locked) {
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
}
