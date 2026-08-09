<?php

namespace App\Http\Requests\BusinessManagement\ApprovalRule;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class EditAllUpdateApprovalRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('approval_rules.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('approval_rules.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // Lo que de verdad se retoca en lote es el orden de las firmas y
            // si cada una es obligatoria. El país, el tipo y el rol no: cambiar
            // eso es cambiar de regla, y va por el formulario.
            'changes.*.priority_level' => 'sometimes|required|integer|min:1|max:20',
            'changes.*.is_required'    => 'sometimes|nullable|boolean',
            'changes.*.is_active'      => 'sometimes|nullable|boolean',
        ];
    }

    /**
     * Dos firmas del mismo flujo no pueden ir en el mismo nivel.
     *
     * Aquí el índice único es compuesto —`(tenant_id, country_id,
     * approver_role, priority_level)`— y de esas cuatro columnas la pantalla
     * sólo deja tocar la última. Poner el nivel 2 en una fila cuyo flujo ya
     * tiene un 2 llegaba tal cual a Postgres: 500, y el lote entero perdido.
     * Por eso no usa el trait de unicidad: no es un valor único, es un valor
     * único **dentro de su flujo**, y el flujo hay que ir a buscarlo a la base
     * porque no viaja en el formulario.
     *
     * Ojo con el flujo: son las tres columnas del índice, no `work_type_id`
     * —que se añadió después y NO entró en el índice—. Comparar por el tipo de
     * trabajo dejaría pasar el choque que revienta.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $cambios = $this->input('changes');

            if (! is_array($cambios)) {
                return;
            }

            $ids = array_values(array_filter(array_map(
                fn ($c) => is_array($c) && isset($c['id']) ? (int) $c['id'] : null,
                $cambios,
            )));

            if ($ids === []) {
                return;
            }

            // Una consulta para todo el lote: a qué flujo pertenece cada fila.
            $flujos = DB::table('approval_rules')->whereIn('id', $ids)
                ->get(['id', 'tenant_id', 'country_id', 'approver_role'])
                ->keyBy('id');

            $vistos = [];

            foreach ($cambios as $i => $c) {
                if (! is_array($c) || ! isset($c['priority_level'], $c['id'])) {
                    continue;
                }

                $fila = $flujos[(int) $c['id']] ?? null;

                if ($fila === null) {
                    continue;
                }

                $nivel = (int) $c['priority_level'];
                $flujo = implode('|', [$fila->tenant_id ?? 0, $fila->country_id, $fila->approver_role]);

                if (isset($vistos["{$flujo}#{$nivel}"])) {
                    $v->errors()->add(
                        "changes.{$i}.priority_level",
                        __('global.value_repeated_in_batch', ['row' => $vistos["{$flujo}#{$nivel}"] + 1]),
                    );

                    continue;
                }

                $vistos["{$flujo}#{$nivel}"] = $i;

                $choca = DB::table('approval_rules')
                    ->whereNull('deleted_at')
                    ->whereNotIn('id', $ids)
                    ->whereRaw('coalesce(tenant_id, 0) = ?', [(int) ($fila->tenant_id ?? 0)])
                    ->where('country_id', $fila->country_id)
                    ->where('approver_role', $fila->approver_role)
                    ->where('priority_level', $nivel)
                    ->exists();

                if ($choca) {
                    $v->errors()->add("changes.{$i}.priority_level", __('approval_rules.priority_level_taken'));
                }
            }
        });
    }
}
