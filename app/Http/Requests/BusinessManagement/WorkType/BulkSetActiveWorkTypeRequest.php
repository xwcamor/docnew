<?php

namespace App\Http\Requests\BusinessManagement\WorkType;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work_types.edit') ?? false;
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
            'is_active.required' => __('work_types.is_active_required'),
        ];
    }
}
