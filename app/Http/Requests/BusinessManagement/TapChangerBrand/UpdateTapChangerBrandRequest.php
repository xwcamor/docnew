<?php

namespace App\Http\Requests\BusinessManagement\TapChangerBrand;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateTapChangerBrandRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tap_changer_brands';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $tapChangerBrand = $this->route('tapChangerBrand');
        if (is_object($tapChangerBrand) && $tapChangerBrand->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $tapChangerBrand   = $this->route('tapChangerBrand');
        $tapChangerBrandId = is_object($tapChangerBrand) ? $tapChangerBrand->id : null;

        return [
            // Unicidad de name case + accent insensitive (catalogo GLOBAL, sin
            // tenant), ignorando el propio tapChangerBrand y soft-deleted. NO se filtra
            // por tenant_id: la tabla no tiene esa columna (filtrarla revienta
            // en Postgres con un 500 al crear/editar).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($tapChangerBrandId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('tap_changer_brands')
                        ->whereNull('deleted_at')
                        ->when($tapChangerBrandId, fn ($qq) => $qq->where('id', '!=', $tapChangerBrandId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('tap_changer_brands.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($tapChangerBrandId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('tap_changer_brands')
                        ->whereNull('deleted_at')
                        ->when($tapChangerBrandId, fn ($qq) => $qq->where('id', '!=', $tapChangerBrandId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('tap_changer_brands.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
