<?php

namespace App\Http\Requests\SystemManagement\Plan;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update de un plan existente (no create/delete — los 4 planes son fixed).
 * Solo super via middleware en routes.
 *
 * `max_users` y `max_records_per_module` aceptan -1 = ilimitado (mapea a
 * PHP_INT_MAX en runtime). Cualquier valor >= -1 es válido.
 */
class UpdatePlanRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'plans';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                   => ['required', 'string', 'max:100'],
            'tagline'                => ['nullable', 'string', 'max:200'],
            'icon'                   => ['nullable', 'string', 'max:60'],
            'color'                  => ['nullable', 'string', 'max:30'],
            'max_users'              => ['required', 'integer', 'min:-1'],
            'max_records_per_module' => ['required', 'integer', 'min:-1'],
            'export_rate_limit'      => ['required', 'integer', 'min:1', 'max:10000'],
            'support_level'          => ['required', 'string', Rule::in(\App\Models\Plan::SUPPORT_LEVELS)],
            'price_monthly'          => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'price_yearly'           => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency'               => ['required', 'string', 'size:3'],
            'is_active'              => ['sometimes', 'boolean'],
            'is_public'              => ['sometimes', 'boolean'],

            // Features bool — cada key es un toggle.
            'features'                => ['nullable', 'array'],
            'features.*'              => ['boolean'],
        ];
    }
}
