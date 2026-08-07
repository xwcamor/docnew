<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use Illuminate\Foundation\Http\FormRequest;

class DeleteFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $formTemplate = $this->route('formTemplate');
        if (is_object($formTemplate) && $formTemplate->is_locked) {
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
