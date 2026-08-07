<?php

namespace App\Http\Requests\SystemManagement\SystemModule;

use App\Rules\UniqueNormalizedName;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'system_modules';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge([
                'name' => Str::studly(Str::singular(trim($this->name))),
            ]);
        }
    }

    public function rules(): array
    {
        $module = $this->route('system_module');

        return [
            'name' => [
                'required', 'string', 'max:255',
                new UniqueNormalizedName('system_modules', 'name', ignoreId: $module?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('system_modules.name_required'),
        ];
    }
}
