<?php

namespace App\Http\Requests\BusinessManagement\Position;

use Illuminate\Validation\Rule;

/**
 * Editar un cargo.
 *
 * Extiende al alta a proposito: la regla de «ya existe ese cargo» es la misma
 * —mismo pais, mismo workspace, sin distinguir mayusculas ni tildes— y escrita
 * dos veces se separa en cuanto alguien corrige una sola. Aqui solo cambia lo
 * que de verdad es distinto: el candado, los interruptores y saltarse la propia
 * fila al comprobar el nombre.
 */
class UpdatePositionRequest extends StorePositionRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre»
        // en vez de «esta bloqueado», que es lo que pasa de verdad.
        $position = $this->route('position');
        if (is_object($position) && $position->is_locked) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Un interruptor apagado no viaja como `false`: viaja ausente. Si se
        // deja pasar, la regla `sometimes` conserva el valor anterior y lo que
        // alguien acaba de desmarcar sigue encendido despues de guardar. Aqui
        // solo queda el de estado, pero el problema no era suyo sino de
        // cualquier casilla del formulario, asi que el bucle se queda: en
        // cuanto entre otro interruptor se le añade el nombre y ya esta
        // cubierto.
        foreach (['is_active'] as $interruptor) {
            $this->merge([$interruptor => $this->boolean($interruptor)]);
        }
    }

    public function rules(): array
    {
        $position = $this->route('position');

        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:60',
                // El conjunto donde se busca el repetido es el del PROPIO
                // registro, no el de quien edita: un super tocando el cargo de
                // una empresa comprueba contra esa empresa, no contra los
                // globales.
                $this->cargoRepetido($position?->id, $position?->tenant_id)],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
