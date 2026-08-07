<?php

namespace App\Http\Requests\AuthManagement\Role;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'name_confirmation' => ['required', 'string'],
            'reason'            => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
