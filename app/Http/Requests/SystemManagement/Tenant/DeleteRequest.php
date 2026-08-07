<?php

// Namespace
namespace App\Http\Requests\SystemManagement\Tenant;

// Use
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Main class
class DeleteRequest extends FormRequest
{
    // Determine if the user is authorized to make this request.
    public function authorize(): bool
    {
        return true;
    }

    // Rules
    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }

    // Get the error messages
    public function messages(): array
    {
        return [
            'deleted_description.required' => __('system_modules.deleted_description_required'),
            'deleted_description.min' => __('system_modules.deleted_description_min'),
            'deleted_description.max' => __('system_modules.deleted_description_max'),
        ];
    }
}
