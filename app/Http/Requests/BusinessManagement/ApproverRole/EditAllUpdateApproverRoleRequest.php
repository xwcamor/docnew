<?php

namespace App\Http\Requests\BusinessManagement\ApproverRole;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateApproverRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approver_roles.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('approver_roles.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // El nombre en español se acepta suelto (el cliente puede mandar
            // solo el estado), pero si viene no puede llegar vacío: un rol sin
            // nombre no se puede elegir en ningún selector.
            'changes.*.name_es'   => 'sometimes|required|string|min:1|max:60',
            'changes.*.is_active' => 'sometimes|nullable|boolean',
        ];
    }
}
