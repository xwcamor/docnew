<?php

namespace App\Http\Requests\BusinessManagement\Company;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'companies';

    protected $attributeOverrides = [
        'country_id' => 'companies.country',
    ];

    protected function prepareForValidation(): void
    {
        // Si no eligen país se asume el del usuario: una contratista casi
        // siempre se da de alta en el país donde se está trabajando.
        if (! $this->filled('country_id')) {
            $this->merge(['country_id' => $this->user()?->country_id]);
        }
        // El RUC se guarda sin espacios ni guiones: así se compara y así se busca.
        if ($this->filled('num_doc')) {
            $this->merge(['num_doc' => preg_replace('/[\s-]/', '', (string) $this->num_doc)]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Unicidad case + accent insensitive. companies es PER-TENANT: el nombre
            // es unico dentro del workspace del actor (no cross-tenant). Se filtra
            // por tenant_id para alinear con el indice unico parcial de la tabla.
            'name' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $isPgsql = DB::getDriverName() === 'pgsql';
                    $needle  = trim((string) $value);
                    $q = DB::table('companies')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id);
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
            'complete_name' => ['required', 'string', 'max:255'],
            // El RUC no se repite dentro del mismo país y workspace — es el
            // mismo criterio del índice único parcial de la tabla.
            'num_doc' => [
                'required', 'string', 'max:20',
                function ($attribute, $value, $fail) {
                    $exists = DB::table('companies')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->where('country_id', $this->input('country_id'))
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
