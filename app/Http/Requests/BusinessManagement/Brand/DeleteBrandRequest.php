<?php

namespace App\Http\Requests\BusinessManagement\Brand;

use Illuminate\Foundation\Http\FormRequest;

class DeleteBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $brand = $this->route('brand');
        if (is_object($brand) && $brand->is_locked) {
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
