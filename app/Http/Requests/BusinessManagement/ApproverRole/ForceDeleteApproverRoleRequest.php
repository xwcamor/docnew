<?php

namespace App\Http\Requests\BusinessManagement\ApproverRole;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteApproverRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            // Se confirma escribiendo el codigo del rol — es su clave real.
            'name_confirmation' => 'required|string',
            'reason'            => 'required|string|min:10|max:500',
        ];
    }
}
