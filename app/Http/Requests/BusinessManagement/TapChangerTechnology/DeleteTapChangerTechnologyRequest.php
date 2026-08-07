<?php

namespace App\Http\Requests\BusinessManagement\TapChangerTechnology;

use Illuminate\Foundation\Http\FormRequest;

class DeleteTapChangerTechnologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $tapChangerTechnology = $this->route('tapChangerTechnology');
        if (is_object($tapChangerTechnology) && $tapChangerTechnology->is_locked) {
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
