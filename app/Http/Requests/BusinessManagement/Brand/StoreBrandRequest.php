<?php

namespace App\Http\Requests\BusinessManagement\Brand;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\DerivesCodeFromName;
use Illuminate\Support\Facades\DB;
class StoreBrandRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'brands';

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
            // Unicidad case + accent insensitive. brands es PER-TENANT: el nombre
            // es unico dentro del workspace del actor (no cross-tenant). Se filtra
            // por tenant_id para alinear con el indice unico parcial de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('brands')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id);
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('brands.name_unique'));
                    }
                },
            ],
            'code'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('brands')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('brands.code_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
