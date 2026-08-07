<?php

namespace App\Http\Requests\BusinessManagement\TapChangerBrand;

use Illuminate\Foundation\Http\FormRequest;

class DeleteTapChangerBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $tapChangerBrand = $this->route('tapChangerBrand');
        if (is_object($tapChangerBrand) && $tapChangerBrand->is_locked) {
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
