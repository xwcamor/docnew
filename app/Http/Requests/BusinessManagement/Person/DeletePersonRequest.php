<?php

namespace App\Http\Requests\BusinessManagement\Person;

use Illuminate\Foundation\Http\FormRequest;

class DeletePersonRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se elimina hasta desbloquearlo.
        $person = $this->route('person');
        if (is_object($person) && $person->is_locked) {
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
