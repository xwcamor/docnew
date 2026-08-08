<?php

namespace App\Http\Requests\BusinessManagement\ApprovalRule;

use Illuminate\Foundation\Http\FormRequest;

class DeleteApprovalRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $approvalRule = $this->route('approvalRule');
        if (is_object($approvalRule) && $approvalRule->is_locked) {
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
