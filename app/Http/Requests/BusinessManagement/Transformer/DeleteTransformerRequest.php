<?php

namespace App\Http\Requests\BusinessManagement\Transformer;

use Illuminate\Foundation\Http\FormRequest;

class DeleteTransformerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $transformer = $this->route('transformer');
        return ! (is_object($transformer) && $transformer->is_locked);
    }

    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }
}
