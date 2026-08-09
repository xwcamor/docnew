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
                $this->permissionKeyIsFree($module?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Igual que en el alta: el UNIQUE de `permission_key` cuenta también los
     * módulos de la papelera. Renombrar un módulo hacia el nombre de otro
     * eliminado daba un 23505 sin explicación. Ver StoreRequest.
     */
    private function permissionKeyIsFree(?int $ignoreId): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
            $key = Str::plural(Str::snake(Str::singular((string) $value)));

            $ocupada = \App\Models\SystemModule::withTrashed()
                ->where('permission_key', $key)
                ->whereNotNull('deleted_at')
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();

            if ($ocupada) {
                $fail(__('system_modules.permission_key_in_trash', ['key' => $key]));
            }
        };
    }

    public function messages(): array
    {
        return [
            'name.required' => __('system_modules.name_required'),
        ];
    }
}
