<?php

namespace App\Http\Requests\BusinessManagement\Nationality;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveNationalityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nationalities.edit') ?? false;
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
            'is_active.required' => __('nationalities.is_active_required'),
        ];
    }
}
