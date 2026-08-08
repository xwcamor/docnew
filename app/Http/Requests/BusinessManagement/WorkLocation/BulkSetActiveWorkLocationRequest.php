<?php

namespace App\Http\Requests\BusinessManagement\WorkLocation;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveWorkLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('work_locations.edit') ?? false;
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
            'is_active.required' => __('work_locations.is_active_required'),
        ];
    }
}
