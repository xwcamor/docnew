<?php

namespace App\Http\Requests\BusinessManagement\Person;

use Illuminate\Validation\Rule;

/**
 * Los roles en obra son de la gente del propio workspace, y punto.
 *
 * Un rol dice qué APRUEBA la persona, y quien autoriza un plan es del que
 * contrata, no de la contratista: a un electricista de una contratista no se le
 * pregunta qué aprueba porque no va a aprobar nada.
 *
 * La pantalla ya lo hacía —el campo sale deshabilitado con el motivo debajo—
 * pero solo la pantalla, y una regla que solo vive en el navegador no es una
 * regla. Bastaba un POST a mano, o un formulario abierto antes de cambiar la
 * empresa, para colar un supervisor de una contratista: a partir de ahí sale en
 * el selector de aprobadores del plan y ya está firmando lo que no le toca.
 *
 * Con el workspace sin empresa marcada (Ajustes → Mi empresa) no hay contra qué
 * comparar y se deja pasar: es lo mismo que hace la pantalla, y es preferible a
 * que nadie pueda dar de alta a un supervisor hasta que alguien rellene un
 * ajuste que quizá ni sabe que existe.
 */
trait ReglasDeLosRoles
{
    /** @return array<string, array<int, mixed>> */
    protected function reglasDeLosRoles(): array
    {
        return [
            'roles' => ['sometimes', 'array', function ($attribute, $value, $fail) {
                $miEmpresa = $this->user()?->tenant?->company_id;

                if (! $miEmpresa || empty($value)) {
                    return;
                }

                if ((int) $this->input('company_id') !== (int) $miEmpresa) {
                    $fail(__('people.roles_solo_de_mi_empresa'));
                }
            }],
            // Del catálogo, no de una lista escrita aquí. Estaban los tres
            // códigos clavados mientras `approver_roles` era una pantalla que
            // admitía filas nuevas: se podía crear la regla «Jefe de Izaje» y
            // ninguna persona podía tener nunca ese rol, así que el plan
            // quedaba con una firma que nadie iba a firmar.
            'roles.*' => ['string', Rule::exists('approver_roles', 'code')
                ->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
