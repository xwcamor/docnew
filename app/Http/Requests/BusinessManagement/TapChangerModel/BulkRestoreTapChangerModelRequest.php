<?php

namespace App\Http\Requests\BusinessManagement\TapChangerModel;

use Illuminate\Foundation\Http\FormRequest;

class BulkRestoreTapChangerModelRequest extends FormRequest
{
    public function authorize(): bool
    {
        // bulk_restore es operacion super: ver routes/business_management.php
        // donde la ruta esta dentro de role:super. Reforzamos aquí como
        // defense-in-depth.
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
