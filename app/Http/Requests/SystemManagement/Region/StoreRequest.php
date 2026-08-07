<?php

namespace App\Http\Requests\SystemManagement\Region;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'regions';

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
                new UniqueNormalizedName('regions', 'name'),
            ],
            // is_active es opcional al crear; el modelo / DB default es true.
            // Cuando viene en el body, debe ser booleano válido.
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('regions.name_required'),
        ];
    }
}
