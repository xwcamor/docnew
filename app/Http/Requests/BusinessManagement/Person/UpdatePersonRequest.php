<?php

namespace App\Http\Requests\BusinessManagement\Person;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use App\Models\PersonRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonRequest extends FormRequest
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
        // El documento se guarda sin espacios ni guiones: así se compara.
        if ($this->filled('num_doc')) {
            $this->merge(['num_doc' => preg_replace('/[\s-]/', '', (string) $this->num_doc)]);
        }

        // Y si quien edita no puede verlo, no lo cambia: se repone el que ya
        // tenía la persona antes de validar nada.
        $this->conservarDocumentoSiEstaTapado();
    }

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        $person = $this->route('person');
        if (is_object($person) && $person->is_locked) {
            return false;
        }
        return true;
    }

    public function rules(): array
    {
        $person   = $this->route('person');
        $personId = is_object($person) ? $person->id : null;

        return [
            'name'     => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'doc_type' => $this->reglasDelTipo(),
            'num_doc'  => $this->reglasDelNumero($personId),
            // La empresa y el cargo viven en el vinculo persona-empresa
            // (ver PersonService::guardarVinculo), no en la persona.
            'company_id'  => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            'roles'       => ['sometimes', 'array'],
            'roles.*'     => ['string', Rule::in([
                PersonRole::WORKER, PersonRole::SUPERVISOR, PersonRole::HSE_SUPERVISOR,
            ])],
            'country_id'     => ['required', 'integer', Rule::exists('countries', 'id')],
            'nationality_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'birthdate'      => ['nullable', 'date', 'before:today'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }
}
