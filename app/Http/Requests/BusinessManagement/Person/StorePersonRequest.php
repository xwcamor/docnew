<?php

namespace App\Http\Requests\BusinessManagement\Person;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use App\Models\PersonRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    use DerivesAttributesFromLang;
    use ReglasDelDocumento;

    protected $attributeNamespace = 'people';

    protected $attributeOverrides = [
        'country_id'     => 'people.country',
        'nationality_id' => 'people.nationality',
        'company_id'     => 'people.company',
        'position_id'    => 'people.position',
        'roles'          => 'people.roles',
    ];

    protected function prepareForValidation(): void
    {
        // Sin país explícito se asume el del usuario: junto con el documento
        // define la unicidad de la persona.
        if (! $this->filled('country_id')) {
            $this->merge(['country_id' => $this->user()?->country_id]);
        }
        // El documento se guarda sin espacios ni guiones: así se compara.
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
            'name'     => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'doc_type' => $this->reglasDelTipo(),
            'num_doc'  => $this->reglasDelNumero(),
            // La empresa y el cargo, que en la v1 son NOT NULL en `workers`.
            // No son columnas de la persona: se guardan en el vinculo
            // persona-empresa (ver PersonService::guardarVinculo).
            'company_id'  => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            // Qué firma en obra. Sin esto no había forma de dar de alta a un
            // supervisor: el selector de aprobadores del plan exige el rol y
            // ninguna pantalla lo ponía (ver WorkPlanSetupController).
            'roles'       => ['sometimes', 'array'],
            'roles.*'     => ['string', Rule::in([
                PersonRole::WORKER, PersonRole::SUPERVISOR, PersonRole::HSE_SUPERVISOR,
            ])],
            'country_id'     => ['required', 'integer', Rule::exists('countries', 'id')],
            // La nacionalidad es un pais: no habia razon para tener dos
            // catalogos de lo mismo, y compararlos por texto casi cuesta caro.
            'nationality_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'birthdate'      => ['nullable', 'date', 'before:today'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }
}
