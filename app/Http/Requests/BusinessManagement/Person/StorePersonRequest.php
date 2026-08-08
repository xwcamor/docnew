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
            // El tipo sale del catálogo, no de una lista escrita aquí. Antes
            // era `Rule::in(['DNI', 'CE', 'PASAPORTE'])`, así que dar de alta a
            // alguien con PTP —que en Perú llevan miles de venezolanos— pasaba
            // por tocar PHP y desplegar.
            'doc_type' => ['required', 'string', 'max:20',
                Rule::exists('document_types', 'code')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->where('is_active', true)
                        ->whereNull('deleted_at'))],
            // La empresa y el cargo, que en la v1 son NOT NULL en `workers`.
            // No son columnas de la persona: se guardan en el vinculo
            // persona-empresa (ver PersonService::guardarVinculo).
            'company_id'  => ['required', 'integer', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->whereNull('deleted_at')],
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
                // Y la longitud que declare ese tipo. Es ayuda al dar de
                // alta y nada más: el buscador de la cuadrilla va por
                // coincidencia exacta, porque en la base hay dos peruanos con
                // DNI de 7 caracteres y una regla de longitud los dejaría
                // fuera del sistema para siempre.
                function ($attribute, $value, $fail) {
                    $tipo = \App\Models\DocumentType::where('country_id', $this->input('country_id'))
                        ->where('code', $this->input('doc_type'))->first();

                    if (! $tipo) {
                        return;
                    }

                    $largo = mb_strlen(trim((string) $value));

                    if ($tipo->min_length && $largo < $tipo->min_length) {
                        $fail(__('people.num_doc_too_short', ['type' => $tipo->code, 'min' => $tipo->min_length]));
                    } elseif ($tipo->max_length && $largo > $tipo->max_length) {
                        $fail(__('people.num_doc_too_long', ['type' => $tipo->code, 'max' => $tipo->max_length]));
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
