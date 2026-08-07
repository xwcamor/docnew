<?php

namespace App\Http\Requests\SystemManagement\Locale;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'locales';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $code = trim($this->code);
            if (str_contains($code, '_')) {
                [$lang, $regn] = explode('_', $code, 2);
                $code = strtolower($lang) . '_' . strtoupper($regn);
            } else {
                $code = strtolower($code);
            }
            $this->merge(['code' => $code]);
        }
    }

    public function rules(): array
    {
        $locale = $this->route('locale');

        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('locales', 'name', ignoreId: $locale?->id),
            ],
            'code' => [
                'required', 'string', 'max:10',
                'regex:/^[a-z]{2}(_[A-Z]{2})?$/',
                Rule::unique('locales', 'code')
                    ->ignore($locale?->id)
                    ->whereNull('deleted_at'),
            ],
            'language_id' => ['required', 'integer', Rule::exists('languages', 'id')->whereNull('deleted_at')],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => __('locales.name_required'),
            'code.required'        => __('locales.code_required'),
            'code.regex'           => __('locales.code_regex'),
            'code.unique'          => __('locales.code_unique'),
            'language_id.required' => __('locales.language_required'),
            'language_id.exists'   => __('locales.language_invalid'),
        ];
    }
}
