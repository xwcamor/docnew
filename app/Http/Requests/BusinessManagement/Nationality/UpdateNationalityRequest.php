<?php

namespace App\Http\Requests\BusinessManagement\Nationality;

use Illuminate\Validation\Rule;

/**
 * Editar una nacionalidad.
 *
 * Extiende al alta a proposito: la regla de «ya existe esa nacionalidad» es la
 * misma —mismo pais, mismo workspace, sin distinguir mayusculas ni tildes— y
 * escrita dos veces se separa en cuanto alguien corrige una sola. Aqui solo
 * cambia lo que de verdad es distinto: el candado, el interruptor y saltarse la
 * propia fila al comprobar el nombre.
 */
class UpdateNationalityRequest extends StoreNationalityRequest
{
    public function authorize(): bool
    {
        // Registro BLOQUEADO (Lockable): no se edita hasta desbloquearlo.
        // Va aqui y no en el controlador porque `authorize()` corre ANTES de
        // validar: si no, un cuerpo invalido devolveria «falta el nombre» en
        // vez de «esta bloqueado», que es lo que pasa de verdad.
        $nationality = $this->route('nationality');
        if (is_object($nationality) && $nationality->is_locked) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Un interruptor apagado no viaja como `false`: viaja ausente. Si se
        // deja pasar, la regla `sometimes` conserva el valor anterior y lo que
        // alguien acaba de desmarcar sigue encendido despues de guardar.
        foreach (['is_active'] as $interruptor) {
            $this->merge([$interruptor => $this->boolean($interruptor)]);
        }
    }

    public function rules(): array
    {
        $nationality = $this->route('nationality');

        return [
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:60',
                // El conjunto donde se busca la repetida es el del PROPIO
                // registro, no el de quien edita: un super tocando la
                // nacionalidad de una empresa comprueba contra esa empresa, no
                // contra las globales.
                $this->nacionalidadRepetida($nationality?->id, $nationality?->tenant_id)],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
