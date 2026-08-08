<?php

namespace App\Http\Requests\BusinessManagement\Person;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UpdatePersonRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'people';

    protected $attributeOverrides = [
        'country_id'     => 'people.country',
        'nationality_id' => 'people.nationality',
    ];

    protected function prepareForValidation(): void
    {
        // El documento se guarda sin espacios ni guiones: así se compara.
        if ($this->filled('num_doc')) {
            $this->merge(['num_doc' => preg_replace('/[\s-]/', '', (string) $this->num_doc)]);
        }
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
            'doc_type' => ['required', 'string', 'max:20', Rule::in(['DNI', 'CE', 'PASAPORTE'])],
            // La empresa y el cargo, que en la v1 son NOT NULL en `workers`.
            // No son columnas de la persona: se guardan en el vinculo
            // persona-empresa (ver PersonService::guardarVinculo).
            'company_id'  => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
            // Unicidad del documento por país + workspace, ignorando la propia
            // persona y las que están en papelera.
            'num_doc' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) use ($personId, $person) {
                    $countryId = $this->input('country_id')
                        ?? (is_object($person) ? $person->country_id : null);
                    $exists = DB::table('people')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->where('country_id', $countryId)
                        ->where('doc_type', $this->input('doc_type'))
                        ->when($personId, fn ($qq) => $qq->where('id', '!=', $personId))
                        ->where('num_doc', trim((string) $value))
                        ->exists();
                    if ($exists) {
                        $fail(__('people.num_doc_unique'));
                    }
                },
            ],
            'country_id'     => ['required', 'integer', Rule::exists('countries', 'id')],
            'nationality_id' => ['nullable', 'integer', Rule::exists('nationalities', 'id')->whereNull('deleted_at')],
            'birthdate'      => ['nullable', 'date', 'before:today'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }
}
