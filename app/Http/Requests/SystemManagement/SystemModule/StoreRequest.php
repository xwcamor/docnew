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
                $this->permissionKeyIsFree(),
            ],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * La clave de permiso (`work_plans`) se deriva del nombre y tiene UNIQUE
     * de tabla ENTERA — incluye los módulos que están en la papelera. El unique
     * del nombre, en cambio, ignora los eliminados.
     *
     * Con esos dos criterios distintos, dar de alta un módulo con el nombre de
     * otro que se borró pasaba la validación y moría con un 23505 de Postgres:
     * pantalla de error, sin decir qué campo estaba mal. Aquí se comprueba
     * contra la papelera y el problema se cuenta en su propio campo.
     */
    private function permissionKeyIsFree(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $key = \Illuminate\Support\Str::plural(
                \Illuminate\Support\Str::snake(\Illuminate\Support\Str::singular((string) $value))
            );

            $enPapelera = \App\Models\SystemModule::withTrashed()
                ->where('permission_key', $key)
                ->whereNotNull('deleted_at')
                ->exists();

            if ($enPapelera) {
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
