<?php

namespace App\Http\Requests\BusinessManagement\Transformer;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateTransformerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transformers.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('transformers.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            'changes.*.serial'    => 'sometimes|nullable|string|max:100',
        ];
    }
}
