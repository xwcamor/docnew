<?php

namespace App\Http\Requests\BusinessManagement\DocumentType;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentTypeRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'document_types';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $documentType = $this->route('documentType');
        if (is_object($documentType) && $documentType->is_locked) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => trim((string) $this->input('code'))]);
        }

        // Un interruptor apagado no viaja como `false`: viaja ausente. Si se
        // deja pasar, la regla `sometimes` conserva el valor anterior y lo que
        // alguien acaba de desmarcar sigue encendido despues de guardar.
        foreach (['is_active'] as $interruptor) {
            $this->merge([$interruptor => $this->boolean($interruptor)]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:20',
                Rule::unique('document_types', 'code')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at'))
                    ->ignore($this->route('documentType')?->id)],
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
