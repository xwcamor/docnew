<?php

namespace App\Http\Requests\AutomationManagement\Automation;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAutomationRequest extends FormRequest
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
}
