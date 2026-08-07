<?php

namespace App\Http\Requests\BusinessManagement\Brand;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateBrandRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'brands';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $brand = $this->route('brand');
        if (is_object($brand) && $brand->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $brand   = $this->route('brand');
        $brandId = is_object($brand) ? $brand->id : null;

        return [
            // Unicidad de name case + accent insensitive PER-TENANT, ignorando el
            // propio brand y soft-deleted. Se filtra por tenant_id para alinear con
            // el indice unico parcial (tenant_id, name) de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($brandId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('brands')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($brandId, fn ($qq) => $qq->where('id', '!=', $brandId));
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
                function ($attribute, $value, $fail) use ($brandId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('brands')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($brandId, fn ($qq) => $qq->where('id', '!=', $brandId))
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
