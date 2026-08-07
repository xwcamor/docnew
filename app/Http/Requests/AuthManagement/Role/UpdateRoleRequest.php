<?php

namespace App\Http\Requests\AuthManagement\Role;

use App\Models\Role;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validacion al editar un rol custom. La unicidad de name se evalua dentro
 * del scope (tenant_id, guard_name) ignorando el rol actual.
 *
 * El tenant_id NO se cambia en update — se toma del rol existente para
 * evaluar la unicidad. El controller ya valida que no sea un rol del sistema
 * ni de otro tenant antes de llegar aquí.
 */
class UpdateRoleRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'roles';

    public function authorize(): bool
    {
        $user = $this->user();
        return $user !== null && $user->hasAnyRole(['super', 'admin']);
    }

    public function rules(): array
    {
        // Resolvemos el rol del route binding para fijar tenant_id e ignoreId.
        $role = $this->route('role');
        $role = $role instanceof Role ? $role : Role::find($role);

        $ignoreId = $role?->id ?? 0;
        $tenantId = $role?->tenant_id;

        return [
            'name'          => ['required', 'string', 'max:120',
                Rule::unique('roles', 'name')->ignore($ignoreId)
                    ->where(function ($q) use ($tenantId) {
                        $q->where('guard_name', 'web');
                        $tenantId === null
                            ? $q->whereNull('tenant_id')
                            : $q->where('tenant_id', $tenantId);
                    }),
            ],
            'description'   => ['required', 'string', 'max:255'],
            // Un perfil debe tener al menos 1 permiso (sin permisos no sirve).
            'permissions'   => ['required', 'array', 'min:1'],
            'permissions.*' => ['integer', Rule::exists('permissions', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.required' => __('roles.permissions_required'),
            'permissions.min'      => __('roles.permissions_required'),
        ];
    }
}
