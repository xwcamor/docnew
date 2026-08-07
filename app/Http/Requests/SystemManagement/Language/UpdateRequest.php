<?php

namespace App\Http\Requests\SystemManagement\Language;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'languages';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $language = $this->route('language');

        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('languages', 'name', ignoreId: $language?->id),
            ],
            'iso_code'  => [
                'required', 'string', 'max:10', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/',
                Rule::unique('languages', 'iso_code')
                    ->ignore($language?->id)
                    ->whereNull('deleted_at'),
            ],
            // sometimes permite partial updates desde la API.
            'is_active' => ['sometimes', 'boolean'],
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
