<?php

namespace App\Http\Requests\SystemManagement\Language;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'languages';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                // Accent + case insensitive, ignores soft-deleted records.
                new UniqueNormalizedName('languages', 'name'),
            ],
            // ISO 639-1 (es) o BCP-47 short (es_AR). Particularidad de Languages
            // que NO tiene Region. Lo preservamos en todas las capas.
            'iso_code'  => [
                'required', 'string', 'max:10', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/',
                Rule::unique('languages', 'iso_code')->whereNull('deleted_at'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => __('languages.name_required'),
            'iso_code.required' => __('languages.iso_code_required'),
            'iso_code.regex'    => __('languages.iso_code_regex'),
            'iso_code.unique'   => __('languages.iso_code_unique'),
        ];
    }
}
