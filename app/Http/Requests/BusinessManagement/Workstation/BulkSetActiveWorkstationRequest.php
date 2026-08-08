<?php

namespace App\Http\Requests\BusinessManagement\Workstation;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveWorkstationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('workstations.edit') ?? false;
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
            'is_active.required' => __('workstations.is_active_required'),
        ];
    }
}
