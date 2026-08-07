<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'customers';

    public function authorize(): bool
    {
        // Un usuario RESTRINGIDO a clientes asignados (customer_user) no crea
        // clientes nuevos: está acotado a su cartera, un cliente nuevo quedaría
        // huérfano (no asignado → ni siquiera lo vería). Cubre store + quickStore.
        return empty($this->user()?->assignedCustomerIds());
    }

    public function rules(): array
    {
        $isSuper = $this->user()?->hasRole('super') ?? false;
        // Super elige el workspace en el form; la unicidad se evalúa contra ESE
        // tenant. Admin: siempre su propio tenant (ignora cualquier input).
        $tenantId = ($isSuper && $this->filled('tenant_id'))
            ? (int) $this->input('tenant_id')
            : $this->user()?->tenant_id;

        $rules = [
            // Unicidad case + accent insensitive dentro del workspace.
            // "Acme S.A." y "ACME S.A." se consideran duplicados — mismo
            // patron que Regions/Languages/etc. El constraint UNIQUE de la
            // BD es el ultimo guardrail; esta validacion lo detecta antes.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tenantId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('customers')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at');
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
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at')),
            ],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'address'    => ['nullable', 'string', 'max:255'],
            'logo'       => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,svg,webp', 'max:2048'],
            'is_active'  => ['sometimes', 'boolean'],
        ];

        // Solo super puede asignar workspace (nullable = global: hay clientes
        // globales). Para los demás la regla NO existe → tenant_id nunca entra en
        // validated() y el trait BelongsToTenant fuerza su propio tenant.
        if ($isSuper) {
            $rules['tenant_id'] = ['nullable', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }
}
