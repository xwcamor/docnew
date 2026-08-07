<?php

namespace App\Http\Requests\BusinessManagement\Company;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
class UpdateCompanyRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'companies';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $company = $this->route('company');
        if (is_object($company) && $company->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $company   = $this->route('company');
        $companyId = is_object($company) ? $company->id : null;

        return [
            // Unicidad de name case + accent insensitive PER-TENANT, ignorando el
            // propio company y soft-deleted. Se filtra por tenant_id para alinear con
            // el indice unico parcial (tenant_id, name) de la tabla.
            'name'       => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($companyId) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('companies')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($companyId, fn ($qq) => $qq->where('id', '!=', $companyId));
                    if ($isPgsql) {
                        $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$needle]);
                    } else {
                        $q->whereRaw('LOWER(name) = LOWER(?)', [$needle]);
                    }
                    if ($q->exists()) {
                        $fail(__('companies.name_unique'));
                    }
                },
            ],
            'num_doc'       => [
                'nullable', 'string', 'max:40',
                function ($attribute, $value, $fail) use ($companyId) {
                    if ($value === null || $value === '') return;
                    $exists = DB::table('companies')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->when($companyId, fn ($qq) => $qq->where('id', '!=', $companyId))
                        ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $value)])
                        ->exists();
                    if ($exists) {
                        $fail(__('companies.num_doc_unique'));
                    }
                },
            ],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
