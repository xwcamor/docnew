<?php

namespace App\Http\Requests\BusinessManagement\TapChangerModel;

use Illuminate\Foundation\Http\FormRequest;

class BulkSetActiveTapChangerModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tap_changer_models.edit') ?? false;
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
            'is_active.required' => __('tap_changer_models.is_active_required'),
        ];
    }
}
