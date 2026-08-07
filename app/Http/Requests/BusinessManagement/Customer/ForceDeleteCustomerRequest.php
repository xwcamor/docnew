<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'name_confirmation' => 'required|string',
            'reason'            => 'required|string|min:10|max:500',
        ];
    }
}
