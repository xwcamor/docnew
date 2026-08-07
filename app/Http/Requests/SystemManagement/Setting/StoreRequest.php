<?php

namespace App\Http\Requests\SystemManagement\Setting;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'settings';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('key')) {
            $this->merge(['key' => strtolower(trim($this->key))]);
        }
    }

    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:100',
                'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/',
                Rule::unique('settings', 'key')->whereNull('deleted_at'),
            ],
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('settings', 'name'),
            ],
            'type'        => ['required', Rule::in(\App\Models\Setting::TYPES)],
            'value'       => ['nullable', 'string'],
            'group'       => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_secret'   => ['nullable', 'boolean'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('settings.name_required'),
            'key.required'  => __('settings.key_required'),
            'key.regex'     => __('settings.key_regex'),
            'key.unique'    => __('settings.key_unique'),
            'type.required' => __('settings.type_required'),
            'type.in'       => __('settings.type_invalid'),
        ];
    }
}
