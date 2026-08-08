<?php

namespace App\Http\Requests\BusinessManagement\ApprovalRule;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateApprovalRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approval_rules.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('approval_rules.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // Lo que de verdad se retoca en lote es el orden de las firmas y
            // si cada una es obligatoria. El país, el tipo y el rol no: cambiar
            // eso es cambiar de regla, y va por el formulario.
            'changes.*.priority_level' => 'sometimes|required|integer|min:1|max:20',
            'changes.*.is_required'    => 'sometimes|nullable|boolean',
            'changes.*.is_active'      => 'sometimes|nullable|boolean',
        ];
    }
}
