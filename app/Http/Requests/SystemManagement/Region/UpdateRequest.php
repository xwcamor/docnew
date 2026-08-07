<?php

namespace App\Http\Requests\SystemManagement\Region;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'regions';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $region = $this->route('region');

        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('regions', 'name', ignoreId: $region?->id),
            ],
            // sometimes permite partial updates (no romper si el form
            // omite el campo). El controller decide qué hacer con payload
            // parcial.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('regions.name_required'),
        ];
    }
}
