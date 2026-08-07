<?php

namespace App\Http\Requests\BusinessManagement\OilType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateOilTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'oil_types';

    public function authorize(): bool
    {
        return true;
    }

    /** Normaliza el campo de clonado: '' / 0 → null (evita que falle la validación). */
    protected function prepareForValidation(): void
    {
        $v = $this->input('clone_rules_from');
        if ($v === '' || $v === 0 || $v === '0') {
            $this->merge(['clone_rules_from' => null]);
        }
    }

    public function rules(): array
    {
        $oilType   = $this->route('oilType');
        $oilTypeId = is_object($oilType) ? $oilType->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio oilType y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($oilTypeId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('oil_types')
                        ->whereNull('deleted_at')
                        ->when($oilTypeId, fn ($qq) => $qq->where('id', '!=', $oilTypeId));
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
                function ($attribute, $value, $fail) use ($oilTypeId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('oil_types')
                        ->whereNull('deleted_at')
                        ->when($oilTypeId, fn ($qq) => $qq->where('id', '!=', $oilTypeId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('oil_types.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
            // Opcional: clonar reglas de otro aceite cuando este aún no tiene
            // (mismo flujo que al crear). Si ya tiene reglas, el form no lo envía.
            'clone_rules_from' => ['nullable', 'integer', 'exists:oil_types,id'],
        ];
    }
}
