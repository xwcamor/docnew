<?php

namespace App\Http\Requests\BusinessManagement\FormTemplate;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateFormTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('form_templates.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('form_templates.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // name aceptado como sometimes (cliente puede mandar solo is_active),
            // pero si viene, NO puede ser empty string ni null. Sin min:1 antes
            // un cliente podÃ­a mandar name:"" y el formTemplate quedaba sin nombre
            // (rompÃ­a unicidad y bÃºsqueda).
            'changes.*.name'      => 'sometimes|required|string|min:1|max:255',
            'changes.*.is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
