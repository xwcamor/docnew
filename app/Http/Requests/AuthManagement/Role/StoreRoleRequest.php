<?php

namespace App\Http\Requests\AuthManagement\Role;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validacion al crear un rol custom.
 *
 * El tenant_id efectivo depende del rol del usuario:
 *   - super: puede elegir tenant_id (null = rol global del sistema)
 *   - admin:       siempre su tenant_id, no puede elegir
 *
 * La unicidad de name se evalua dentro del scope (tenant_id, guard_name).
 */
class StoreRoleRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'roles';

    public function authorize(): bool
    {
        // Las rutas estan en role:super|admin con plan_feature:team_management.
        // Reforzamos aquí el rol minimo.
        $user = $this->user();
        return $user !== null && $user->hasAnyRole(['super', 'admin']);
    }

    public function rules(): array
    {
        $user         = $this->user();
        $isSuper = $user?->hasRole('super') ?? false;

        // tenant efectivo: super elige (nullable=global), admin siempre el suyo.
        $tenantId = $isSuper
            ? ($this->filled('tenant_id') ? (int) $this->input('tenant_id') : null)
            : $user?->tenant_id;

        $rules = [
            'name'          => ['required', 'string', 'max:120',
                Rule::unique('roles', 'name')->where(function ($q) use ($tenantId) {
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

        if ($isSuper) {
            $rules['tenant_id'] = ['nullable', 'integer',
                Rule::exists('tenants', 'id')->whereNull('deleted_at')];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'permissions.required' => __('roles.permissions_required'),
            'permissions.min'      => __('roles.permissions_required'),
        ];
    }
}
