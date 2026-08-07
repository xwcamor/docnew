<?php

namespace App\Http\Requests\AutomationManagement\Automation;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'       => 'required|array|min:1|max:500',
            'ids.*'     => 'integer',
            'is_active' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'       => __('global.bulk_no_selection'),
            'is_active.required' => __('automations.is_active_required'),
        ];
    }
}
