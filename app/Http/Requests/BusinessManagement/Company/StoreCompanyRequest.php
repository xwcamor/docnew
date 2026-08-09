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
            // por tenant_id. OJO: esto lo sostiene SOLO esta regla — en la tabla
            // el indice de `name` es un btree normal, no unico (el unico parcial
            // que si existe es el de `num_doc`). Cualquier escritura que no pase
            // por aqui, como la migracion legacy, puede meter nombres repetidos.
            'name' => [
                'required', 'string', 'min:3', 'max:255',
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
            'complete_name' => ['required', 'string', 'min:3', 'max:255'],
            // El RUC no se repite dentro del mismo país y workspace — es el
            // mismo criterio del índice único parcial de la tabla.
            'num_doc' => [
                // `min:3`: el sistema anterior lo exigia (parsley_minlength en
                // _form.html.erb) y aqui se habia quedado solo el maximo, asi que
                // se podia guardar un RUC de un solo caracter. No pongo
                // `digits:11` porque eso es el RUC peruano y el modulo es
                // multi-pais: cada uno tiene su documento fiscal.
                'required', 'string', 'min:3', 'max:20',
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
