<?php

namespace App\Http\Requests\SystemManagement\Language;

use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('super') ?? false;
    }

    public function rules(): array
    {
        return [
            'name_confirmation' => 'required|string',
            'reason'            => 'required|string|min:10|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'name_confirmation.required' => __('global.force_delete_name_required'),
            'reason.required'            => __('global.force_delete_reason_required'),
            'reason.min'                 => __('global.force_delete_reason_min'),
        ];
    }
}
