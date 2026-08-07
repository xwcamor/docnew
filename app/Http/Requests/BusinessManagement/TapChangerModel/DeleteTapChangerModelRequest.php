<?php

namespace App\Http\Requests\BusinessManagement\TapChangerModel;

use Illuminate\Foundation\Http\FormRequest;

class DeleteTapChangerModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $tapChangerModel = $this->route('tapChangerModel');
        if (is_object($tapChangerModel) && $tapChangerModel->is_locked) {
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
