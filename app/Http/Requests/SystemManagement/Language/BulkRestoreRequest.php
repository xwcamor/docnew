<?php

namespace App\Http\Requests\SystemManagement\Language;

use Illuminate\Foundation\Http\FormRequest;

class BulkRestoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'   => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => __('global.bulk_no_selection'),
        ];
    }
}
