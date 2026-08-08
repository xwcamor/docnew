<?php

namespace App\Http\Requests\BusinessManagement\Workstation;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkstationRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'workstations';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $workstation = $this->route('workstation');
        if (is_object($workstation) && $workstation->is_locked) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
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
            'work_location_id' => ['required', 'integer', Rule::exists('work_locations', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('workstations', 'name')
                    ->where(fn ($q) => $q->where('work_location_id', $this->input('work_location_id'))
                        ->whereNull('deleted_at'))->ignore($this->route('workstation')?->id)],
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
