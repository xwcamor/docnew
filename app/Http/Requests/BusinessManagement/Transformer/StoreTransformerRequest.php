<?php

namespace App\Http\Requests\BusinessManagement\Transformer;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransformerRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'transformers';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isSuper = $this->user()?->hasRole('super') ?? false;
        // Super elige el workspace en el form; la unicidad serie+tag se evalúa
        // contra ESE tenant. Admin: siempre su propio tenant.
        $tenantId = ($isSuper && $this->filled('tenant_id'))
            ? (int) $this->input('tenant_id')
            : $this->user()?->tenant_id;

        $rules = [
            'serial'              => ['required', 'string', 'max:100'],
            // La COMBINACIÓN serie + tag es única por workspace. La serie SOLA sí
            // puede repetirse (una serie puede tener varios tags: abc/123, abc/456);
            // lo que no se permite es serie+tag igual. NO hay índice único en BD
            // (la migración histórica trajo duplicados que deben sobrevivir): se
            // valida aquí, a nivel app, solo para los registros NUEVOS.
            'tag'                 => [
                'required', 'string', 'max:100',
                Rule::unique('transformers', 'tag')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)
                        ->where('serial', $this->input('serial'))
                        ->whereNull('deleted_at')),
            ],
            'customer_id'         => ['required', 'integer', 'exists:customers,id'],
            'customer_substation_id' => ['required', 'integer', 'exists:customer_substations,id'],
            'oil_type_id'         => ['required', 'integer', 'exists:oil_types,id'],
            'transformer_type_id' => ['required', 'integer', 'exists:transformer_types,id'],
            'brand_id'            => ['required', 'integer', 'exists:brands,id'],
            'tap_changer_type_id' => ['required', 'integer', 'exists:tap_changer_types,id'],
            'tap_changer_brand_id'      => ['nullable', 'integer', 'exists:tap_changer_brands,id'],
            'tap_changer_model_id'      => ['nullable', 'integer', 'exists:tap_changer_models,id'],
            'tap_changer_technology_id' => ['nullable', 'integer', 'exists:tap_changer_technologies,id'],
            // Metadatos descriptivos opcionales (no ejes de diagnóstico).
            'connection_type_id'  => ['nullable', 'integer', 'exists:connection_types,id'],
            'transformer_preservation_id' => ['nullable', 'integer', 'exists:transformer_preservations,id'],
            'voltage_kv'          => ['required', 'numeric', 'min:0'],
            'power_mva'           => ['required', 'numeric', 'min:0'],
            'manufacture_year'    => ['required', 'integer', 'min:1900', 'max:2100'],
            // Tipo de papel aislante (opcional). Influye en la interpretación de furanos.
            'paper_type'          => ['nullable', 'in:kraft,upgraded'],
            // Número de fases (obligatorio).
            'phases'              => ['required', 'in:single,two,three'],
            // Fecha de último tratamiento/cambio de aceite (opcional).
            'oil_treated_at'      => ['nullable', 'date'],
        ];

        // Workspace obligatorio para super (no hay trafos globales). Sin la regla
        // para no-super, tenant_id no entra en validated() y el trait
        // BelongsToTenant fuerza el tenant del actor.
        if ($isSuper) {
            $rules['tenant_id'] = ['required', 'integer', 'exists:tenants,id'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'tag.unique'         => __('transformers.serial_tag_unique'),
            'tenant_id.required' => __('tenants.required'),
        ];
    }
}
