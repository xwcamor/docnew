<?php

namespace App\Http\Requests\BusinessManagement\TapChangerType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateTapChangerTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tap_changer_types';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tapChangerType   = $this->route('tapChangerType');
        $tapChangerTypeId = is_object($tapChangerType) ? $tapChangerType->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio tapChangerType y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tapChangerTypeId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('tap_changer_types')
                        ->whereNull('deleted_at')
                        ->when($tapChangerTypeId, fn ($qq) => $qq->where('id', '!=', $tapChangerTypeId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('tap_changer_types.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($tapChangerTypeId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('tap_changer_types')
                        ->whereNull('deleted_at')
                        ->when($tapChangerTypeId, fn ($qq) => $qq->where('id', '!=', $tapChangerTypeId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('tap_changer_types.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
