<?php

namespace App\Http\Requests\BusinessManagement\Position;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActivePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('positions.edit') ?? false;
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
            'is_active.required' => __('positions.is_active_required'),
        ];
    }
}
