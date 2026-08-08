<?php

namespace App\Http\Requests\BusinessManagement\WorkArea;

use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkAreaRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'work_areas';

    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $workArea = $this->route('workArea');
        if (is_object($workArea) && $workArea->is_locked) {
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
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120',
                Rule::unique('work_areas', 'name')
                    ->where(fn ($q) => $q->where('country_id', $this->input('country_id'))
                        ->whereNull('deleted_at'))->ignore($this->route('workArea')?->id)],
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
