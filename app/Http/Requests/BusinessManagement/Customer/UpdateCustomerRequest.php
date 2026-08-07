<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'customers';

    public function authorize(): bool
    {
        // Solo-lectura en Clientes para un usuario acotado a su cartera asignada:
        // gestiona la flota (trafos), no el registro maestro del cliente.
        if (! empty($this->user()?->assignedCustomerIds())) {
            return false;
        }
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $customer = $this->route('customer');
        if (is_object($customer) && $customer->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $customer   = $this->route('customer');
        $customerId = is_object($customer) ? $customer->id : null;

        $isSuper = $this->user()?->hasRole('super') ?? false;
        // Super edita el workspace; la unicidad se evalúa contra el tenant elegido
        // (o el actual del customer). Admin: siempre su propio tenant.
        $tenantId = $isSuper
            ? ($this->filled('tenant_id') ? (int) $this->input('tenant_id') : ($customer->tenant_id ?? null))
            : $this->user()?->tenant_id;

        $rules = [
            // Unicidad de name case + accent insensitive dentro del workspace,
            // ignorando el propio customer y soft-deleted. Sin esta validación,
            // un update a un nombre duplicado revienta con 500 (partial unique
            // index del DB) en lugar de devolver 422.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tenantId, $customerId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('customers')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at')
                        ->when($customerId, fn ($qq) => $qq->where('id', '!=', $customerId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('customers.name_unique'));
                    }
                },
            ],
            'cod'        => [
                'required', 'string', 'max:50',
                // El cod (RUC/CUIT/RFC/NIT) es unico por PAIS dentro del workspace
                // (entre los activos: el indice parcial ignora soft-deleted).
                Rule::unique('customers', 'cod')
                    ->ignore($customerId)
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at')),
            ],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'address'    => ['nullable', 'string', 'max:255'],
            'logo'       => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,svg,webp', 'max:2048'],
            'is_active'  => ['sometimes', 'boolean'],
        ];

        // Solo super puede reasignar workspace (nullable = global: hay clientes
        // globales). Sin la regla para no-super, tenant_id no entra en validated()
        // (evita escape cross-tenant).
        if ($isSuper) {
            $rules['tenant_id'] = ['nullable', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }
}
