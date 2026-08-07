<?php

namespace App\Http\Requests\BusinessManagement\OilType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\Concerns\DerivesCodeFromName;
class StoreOilTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'oil_types';

    use DerivesCodeFromName;

    public function authorize(): bool
    {
        return true;
    }

    /** Normaliza el campo de clonado: '' / 0 → null (evita que falle la validación). */
    protected function prepareForValidation(): void
    {
        $this->deriveCodeFromName();
        $v = $this->input('clone_rules_from');
        if ($v === '' || $v === 0 || $v === '0') {
            $this->merge(['clone_rules_from' => null]);
        }
    }

    public function rules(): array
    {
        return [
            // Unicidad case + accent insensitive. oil_types es un CATALOGO GLOBAL
            // (sin tenant): el nombre es unico para todos los workspaces. NO se
            // filtra por tenant_id porque la tabla no tiene esa columna (filtrar
            // por una columna inexistente revienta en Postgres con un 500).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('oil_types')
                        ->whereNull('deleted_at');
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('oil_types.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('oil_types')
                        ->whereNull('deleted_at')
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('oil_types.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
            // Opcional: clonar las reglas de diagnóstico (cromas + fiquis) de un
            // aceite existente, para que el aceite nuevo no quede "Sin reglas".
            'clone_rules_from' => ['nullable', 'integer', 'exists:oil_types,id'],
        ];
    }
}
