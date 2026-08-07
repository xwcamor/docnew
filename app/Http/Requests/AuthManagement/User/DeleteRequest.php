<?php

namespace App\Http\Requests\AuthManagement\User;

use Illuminate\Foundation\Http\FormRequest;

class DeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'deleted_description.required' => 'El motivo de eliminación es obligatorio.',
            'deleted_description.min'      => 'El motivo debe tener al menos 3 caracteres.',
            'deleted_description.max'      => 'El motivo no debe superar los 1000 caracteres.',
        ];
    }
}
