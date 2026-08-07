<?php

namespace App\Http\Requests\BusinessManagement\TapChangerBrand;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\DerivesCodeFromName;
use Illuminate\Support\Facades\DB;
class StoreTapChangerBrandRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'tap_changer_brands';

    use DerivesCodeFromName;

    protected function prepareForValidation(): void
    {
        $this->deriveCodeFromName();
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unicidad case + accent insensitive. tap_changer_brands es un CATALOGO GLOBAL
            // (sin tenant): el nombre es unico para todos los workspaces. NO se
            // filtra por tenant_id porque la tabla no tiene esa columna (filtrar
            // por una columna inexistente revienta en Postgres con un 500).
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('tap_changer_brands')
                        ->whereNull('deleted_at');
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
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('tap_changer_brands')
                        ->whereNull('deleted_at')
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
