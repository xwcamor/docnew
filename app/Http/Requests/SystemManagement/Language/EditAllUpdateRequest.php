<?php

namespace App\Http\Requests\SystemManagement\Language;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $max = (int) config('languages.edit_all_max', 200);

        return [
            'changes'              => "required|array|min:1|max:{$max}",
            'changes.*.id'         => 'required|integer|exists:languages,id,deleted_at,NULL',
            'changes.*.name'       => 'sometimes|required|string|min:1|max:255',
            'changes.*.iso_code'   => 'sometimes|required|string|min:1|max:10|regex:/^[a-z]{2}(_[A-Z]{2})?$/',
            'changes.*.is_active'  => 'sometimes|nullable|boolean',
        ];
    }
}
