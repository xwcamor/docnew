<?php

namespace App\Http\Requests\SystemManagement\Country;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'countries';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalizamos iso_code/currency a uppercase ANTES de validar
        // para que el regex y el unique trabajen sobre el valor canónico.
        $this->merge([
            'iso_code' => $this->iso_code ? strtoupper(trim($this->iso_code)) : $this->iso_code,
            'currency' => $this->currency ? strtoupper(trim($this->currency)) : $this->currency,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('countries', 'name'),
            ],
            'iso_code' => [
                'required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/',
                Rule::unique('countries', 'iso_code')->whereNull('deleted_at'),
            ],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => [
                'required', 'string', 'max:64',
                function ($attr, $value, $fail) {
                    if (!in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                        $fail(__('countries.timezone_invalid'));
                    }
                },
            ],
            'region_id'         => ['required', 'integer', Rule::exists('regions', 'id')->whereNull('deleted_at')],
            'default_locale_id' => ['required', 'integer', Rule::exists('locales', 'id')->whereNull('deleted_at')],
            'is_active'         => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'              => __('countries.name_required'),
            'iso_code.required'          => __('countries.iso_code_required'),
            'iso_code.regex'             => __('countries.iso_code_regex'),
            'iso_code.unique'            => __('countries.iso_code_unique'),
            'currency.required'          => __('countries.currency_required'),
            'currency.regex'             => __('countries.currency_regex'),
            'timezone.required'          => __('countries.timezone_required'),
            'region_id.required'         => __('countries.region_required'),
            'region_id.exists'           => __('countries.region_invalid'),
            'default_locale_id.required' => __('countries.default_locale_required'),
            'default_locale_id.exists'   => __('countries.default_locale_invalid'),
        ];
    }
}
