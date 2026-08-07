<?php

namespace App\Http\Requests\BusinessManagement\Person;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorePersonRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'people';

    protected $attributeOverrides = [
        'country_id'     => 'people.country',
        'nationality_id' => 'people.nationality',
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
            'doc_type' => ['required', 'string', 'max:20', Rule::in(['DNI', 'CE', 'PASAPORTE'])],
            // Una persona es única por documento dentro de su país y workspace:
            // es exactamente el índice único parcial de la tabla.
            'num_doc' => [
                'required', 'string', 'max:255',
                function ($attribute, $value, $fail) {
                    $exists = DB::table('people')
                        ->whereNull('deleted_at')
                        ->where('tenant_id', auth()->user()?->tenant_id)
                        ->where('country_id', $this->input('country_id'))
                        ->where('doc_type', $this->input('doc_type'))
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
