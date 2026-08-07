<?php

namespace App\Http\Requests\SystemManagement\SystemModule;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'system_modules';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // El Model.setNameAttribute auto-transforma name a PascalCase singular.
        // Aplicamos la MISMA transformación antes de validar para que el unique
        // check compare contra el valor que realmente se va a guardar.
        if ($this->filled('name')) {
            $this->merge([
                'name' => Str::studly(Str::singular(trim($this->name))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('system_modules', 'name'),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('system_modules.name_required'),
        ];
    }
}
