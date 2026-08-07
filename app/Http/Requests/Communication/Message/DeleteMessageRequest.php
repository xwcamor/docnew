<?php

namespace App\Http\Requests\Communication\Message;

use Illuminate\Foundation\Http\FormRequest;

class DeleteMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('super');
    }

    public function rules(): array
    {
        return [
            // Borrado estándar: solo el motivo (queda en el audit log). Sin
            // confirmación por asunto (se quitó para unificar con los demás).
            'deleted_description' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
