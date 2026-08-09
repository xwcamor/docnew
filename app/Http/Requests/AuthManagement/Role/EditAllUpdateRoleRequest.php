<?php

namespace App\Http\Requests\AuthManagement\Role;

use App\Http\Requests\Concerns\ValidatesEditAllUniqueness;
use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateRoleRequest extends FormRequest
{
    use ValidatesEditAllUniqueness;

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

    /**
     * La unicidad, que en «Editar todo» no la comprobaba nadie.
     *
     * Perfiles tiene dos peculiaridades frente al resto, y las dos importan
     * porque la comprobación tiene que decir lo MISMO que el índice:
     *
     *  - Su índice único **no filtra por `deleted_at`**, así que un perfil en
     *    la papelera sigue reservando su nombre. Si aquí lo descartáramos, la
     *    validación pasaría y la base reventaría igual.
     *  - Lleva `guard_name`, que es de Spatie y siempre es `web` en este
     *    producto — pero se compara igualmente, porque comparar de menos
     *    rechaza nombres que la base habría aceptado.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(fn ($v) => $this->comprobarUnicidadDelLote($v, [[
            'campo'    => 'name',
            'tabla'    => 'roles',
            'mensaje'  => 'roles.name_unique',
            'ambito'   => 'workspace',
            'borrados' => false,
            'tildes'   => false,
            'extra'    => fn ($q) => $q->where('guard_name', 'web'),
        ]]));
    }
}
