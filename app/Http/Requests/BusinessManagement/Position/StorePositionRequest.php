<?php

namespace App\Http\Requests\BusinessManagement\Position;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePositionRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'positions';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:positions.create — aqui solo se validan
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
            'code' => ['required', 'string', 'max:60',
                Rule::unique('positions', 'code')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at'))],
            'is_signature_approver' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => __('positions.code_required'),
            'code.unique'   => __('positions.code_unique'),
        ];
    }
}
