<?php

namespace App\Http\Requests\BusinessManagement\Company;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'companies';

    protected $attributeOverrides = [
        'country_id' => 'companies.country',
    ];

    protected function prepareForValidation(): void
    {
        // El RUC se guarda sin espacios ni guiones: así se compara y así se busca.
        if ($this->filled('num_doc')) {
            $this->merge(['num_doc' => preg_replace('/[\s-]/', '', (string) $this->num_doc)]);
        }
    }

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
            // por tenant_id. OJO: ese indice unico (tenant_id, name) NO existe en
            // la tabla — `companies_name_index` es un btree normal. La unicidad
            // del nombre la sostiene solo esta regla.
            'name'       => [
                'required', 'string', 'min:3', 'max:255',
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
            'complete_name' => ['required', 'string', 'min:3', 'max:255'],
            // El RUC no se repite dentro del mismo país y workspace — mismo
            // criterio que el índice único parcial de la tabla.
            'num_doc' => [
                // `min:3`: el sistema anterior lo exigia (parsley_minlength en
                // _form.html.erb) y aqui se habia quedado solo el maximo, asi que
                // se podia guardar un RUC de un solo caracter. No pongo
                // `digits:11` porque eso es el RUC peruano y el modulo es
                // multi-pais: cada uno tiene su documento fiscal.
                'required', 'string', 'min:3', 'max:20',
                function ($attribute, $value, $fail) use ($companyId, $company) {
                    $countryId = $this->input('country_id')
                        ?? (is_object($company) ? $company->country_id : null);
                    $exists = DB::table('companies')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->where('country_id', $countryId)
                        ->when($companyId, fn ($qq) => $qq->where('id', '!=', $companyId))
                        ->where('num_doc', trim((string) $value))
                        ->exists();
                    if ($exists) {
                        $fail(__('companies.num_doc_unique'));
                    }
                },
            ],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'is_active'  => ['sometimes', 'boolean'],
        ];
    }
}
