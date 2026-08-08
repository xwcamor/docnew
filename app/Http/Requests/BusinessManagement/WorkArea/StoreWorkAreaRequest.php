<?php

namespace App\Http\Requests\BusinessManagement\WorkArea;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkAreaRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'work_areas';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:work_areas.create — aqui solo se validan
        // los datos, igual que en el resto de modulos.
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Los espacios de los extremos son un error de tecleo con guantes, no
        // parte del nombre: dos filas que solo se diferencian en un espacio se
        // leen igual en pantalla y confunden a quien elige.
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('work_areas', 'name')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at'))],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('work_areas.name_required'),
            'name.unique'   => __('work_areas.name_unique'),
        ];
    }
}
