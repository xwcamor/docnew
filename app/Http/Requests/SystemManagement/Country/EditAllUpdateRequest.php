<?php

namespace App\Http\Requests\SystemManagement\Country;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // edit_all_max define cuántas filas se pueden tocar en un solo batch.
        // Por encima de eso forzaríamos N validaciones de unicidad → DB pool burn.
        $max = (int) config('countries.edit_all_max', 200);

        return [
            'changes'                       => "required|array|min:1|max:{$max}",
            'changes.*.id'                  => 'required|integer|exists:countries,id,deleted_at,NULL',
            'changes.*.name'                => 'sometimes|required|string|min:1|max:255',
            'changes.*.iso_code'            => 'sometimes|nullable|string|size:2|regex:/^[A-Za-z]{2}$/',
            'changes.*.currency'            => 'sometimes|nullable|string|size:3|regex:/^[A-Za-z]{3}$/',
            'changes.*.timezone'            => 'sometimes|nullable|string|max:64',
            'changes.*.region_id'           => 'sometimes|nullable|integer|exists:regions,id,deleted_at,NULL',
            'changes.*.default_locale_id'   => 'sometimes|nullable|integer|exists:locales,id,deleted_at,NULL',
            'changes.*.is_active'           => 'sometimes|nullable|boolean',
        ];
    }
}
