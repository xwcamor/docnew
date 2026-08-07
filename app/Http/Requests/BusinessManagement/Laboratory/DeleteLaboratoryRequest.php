<?php

namespace App\Http\Requests\BusinessManagement\Laboratory;

use Illuminate\Foundation\Http\FormRequest;

class DeleteLaboratoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $laboratory = $this->route('laboratory');
        if (is_object($laboratory) && $laboratory->is_locked) {
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
