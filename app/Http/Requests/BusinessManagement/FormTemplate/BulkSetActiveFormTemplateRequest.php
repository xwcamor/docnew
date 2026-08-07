<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('form_templates.edit') ?? false;
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
            'is_active.required' => __('form_templates.is_active_required'),
        ];
    }
}
