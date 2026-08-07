<?php

namespace App\Http\Requests\SystemManagement\Country;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveRequest extends FormRequest
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
            'is_active.required' => __('countries.is_active_required'),
        ];
    }
}
