<?php

namespace App\Http\Requests\BusinessManagement\WorkArea;

use Illuminate\Validation\Rule;

/**
 * Editar un area.
 *
 * Extiende al alta a proposito: la regla de «ya existe esa area» es la misma
 * —mismo pais, mismo workspace, sin distinguir mayusculas ni tildes— y escrita
 * dos veces se separa en cuanto alguien corrige una sola. Aqui solo cambia lo
 * que de verdad es distinto: el candado, el interruptor y saltarse la propia
 * fila al comprobar el nombre.
 */
class UpdateWorkAreaRequest extends StoreWorkAreaRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre»
        // en vez de «esta bloqueado», que es lo que pasa de verdad.
        $workArea = $this->route('workArea');
        if (is_object($workArea) && $workArea->is_locked) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Un interruptor apagado no viaja como `false`: viaja ausente. Si se
        // deja pasar, la regla `sometimes` conserva el valor anterior y el area
        // que alguien acaba de desactivar sigue ofreciendose en los planes
        // nuevos. Es el mismo fallo que se vio en «puede firmar aprobaciones»
        // de los cargos.
        foreach (['is_active'] as $interruptor) {
            $this->merge([$interruptor => $this->boolean($interruptor)]);
        }
    }

    public function rules(): array
    {
        $workArea = $this->route('workArea');

        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            // El conjunto donde se busca la repetida es el del PROPIO registro,
            // no el de quien edita: un super tocando el area de una empresa
            // comprueba contra esa empresa, no contra las globales.
            'name' => ['required', 'string', 'max:120',
                $this->areaRepetida($workArea?->id, $workArea?->tenant_id)],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
