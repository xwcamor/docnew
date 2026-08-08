<?php

namespace App\Http\Requests\BusinessManagement\DocumentType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'document_types';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:document_types.create — aqui solo se validan
        // los datos, igual que en el resto de modulos.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Los espacios de los extremos son un error de tecleo con guantes, no
        // parte del nombre: dos filas que solo se diferencian en un espacio se
        // leen igual en pantalla y confunden a quien elige.
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:20',
                Rule::unique('document_types', 'code')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at'))],
            'name' => ['nullable', 'string', 'max:120'],
            // Ayuda de validacion del numero, no condicion de busqueda: el
            // buscador de la cuadrilla va por coincidencia exacta. El volcado
            // trae dos peruanos con DNI de 7 caracteres, asi que una regla de
            // longitud mal puesta deja gente fuera del sistema.
            'min_length' => ['nullable', 'integer', 'min:1', 'max:40'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:40', 'gte:min_length'],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => __('document_types.code_required'),
            'code.unique'   => __('document_types.code_unique'),
        ];
    }
}
