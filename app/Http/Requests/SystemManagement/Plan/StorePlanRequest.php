<?php

namespace App\Http\Requests\SystemManagement\Plan;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create de un plan nuevo. El `slug` es obligatorio y único — se usa como
 * identificador en `tenants.plan` y `subscriptions.plan`.
 */
class StorePlanRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'plans';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('slug')) {
            $this->merge(['slug' => strtolower(trim($this->slug))]);
        }
    }

    public function rules(): array
    {
        return [
            'slug' => [
                'required', 'string', 'max:60',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('plans', 'slug'),
            ],
            'name'                   => ['required', 'string', 'max:100'],
            'tagline'                => ['nullable', 'string', 'max:200'],
            'icon'                   => ['nullable', 'string', 'max:60'],
            'color'                  => ['nullable', 'string', 'max:30'],
            'sort_order'             => ['nullable', 'integer', 'min:0'],
            'max_users'              => ['required', 'integer', 'min:-1'],
            'max_records_per_module' => ['required', 'integer', 'min:-1'],
            'export_rate_limit'      => ['required', 'integer', 'min:1', 'max:10000'],
            'support_level'          => ['required', 'string', Rule::in(\App\Models\Plan::SUPPORT_LEVELS)],
            'price_monthly'          => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'price_yearly'           => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'currency'               => ['required', 'string', 'size:3'],
            'is_active'              => ['sometimes', 'boolean'],
            'is_public'              => ['sometimes', 'boolean'],
            'features'               => ['nullable', 'array'],
            'features.*'             => ['boolean'],
        ];
    }
}
