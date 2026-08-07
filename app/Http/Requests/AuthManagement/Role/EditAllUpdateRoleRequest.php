<?php

namespace App\Http\Requests\AuthManagement\Role;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->hasAnyRole(['super', 'admin']);
    }

    public function rules(): array
    {
        // 200 alineado con Regions/Customers/Automations.
        $max = (int) config('roles.edit_all_max', 200);

        return [
            'changes'               => "required|array|min:1|max:{$max}",
            'changes.*.id'          => 'required|integer',
            'changes.*.name'        => 'sometimes|required|string|min:1|max:255',
            'changes.*.description' => 'sometimes|nullable|string|max:500',
            'changes.*.is_active'   => 'sometimes|nullable|boolean',
        ];
    }
}
