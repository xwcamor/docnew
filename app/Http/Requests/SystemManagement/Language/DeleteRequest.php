<?php

namespace App\Http\Requests\SystemManagement\Language;

use Illuminate\Foundation\Http\FormRequest;

class DeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'deleted_description.required' => __('languages.deleted_description_required'),
            'deleted_description.min'      => __('languages.deleted_description_min'),
            'deleted_description.max'      => __('languages.deleted_description_max'),
        ];
    }
}
