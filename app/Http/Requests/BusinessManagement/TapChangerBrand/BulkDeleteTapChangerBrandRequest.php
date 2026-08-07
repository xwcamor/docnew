<?php

namespace App\Http\Requests\BusinessManagement\TapChangerBrand;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteTapChangerBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El middleware permission:tap_changer_brands.delete ya gatea la ruta â€” este
        // authorize() esta aquí por consistencia con el patron FormRequest.
        return $this->user()?->can('tap_changer_brands.delete') ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'                 => 'required|array|min:1|max:500',
            'ids.*'               => 'integer',
            'deleted_description' => 'required|string|min:3|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'                 => __('global.bulk_no_selection'),
            'deleted_description.required' => __('global.delete_reason_required'),
            'deleted_description.min'      => __('global.delete_reason_min_3'),
        ];
    }
}
