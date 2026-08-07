<?php

namespace App\Http\Requests\AutomationManagement\Automation;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteAutomationRequest extends FormRequest
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
