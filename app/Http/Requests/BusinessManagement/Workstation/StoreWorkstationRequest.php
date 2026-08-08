<?php

namespace App\Http\Requests\BusinessManagement\Workstation;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkstationRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'workstations';

    public function authorize(): bool
    {
        // La ruta ya pasa por permission:workstations.create — aqui solo se validan
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
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('workstations', 'name')
                    ->where(fn ($q) => $q->where('work_location_id', $this->input('work_location_id'))
                        ->whereNull('deleted_at'))],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('workstations.name_required'),
            'name.unique'   => __('workstations.name_unique'),
        ];
    }
}
