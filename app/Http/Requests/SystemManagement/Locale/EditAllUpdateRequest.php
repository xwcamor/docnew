<?php

namespace App\Http\Requests\SystemManagement\Locale;

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
        $max = (int) config('locales.edit_all_max', 200);

        return [
            'changes'                => "required|array|min:1|max:{$max}",
            'changes.*.id'           => 'required|integer|exists:locales,id,deleted_at,NULL',
            'changes.*.name'         => 'sometimes|required|string|min:1|max:255',
            'changes.*.code'         => 'sometimes|required|string|min:1|max:10|regex:/^[a-z]{2}(_[A-Z]{2})?$/',
            'changes.*.language_id'  => 'sometimes|nullable|integer|exists:languages,id,deleted_at,NULL',
            'changes.*.is_active'    => 'sometimes|nullable|boolean',
        ];
    }
}
