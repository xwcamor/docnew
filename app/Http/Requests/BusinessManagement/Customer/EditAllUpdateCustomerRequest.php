<?php

namespace App\Http\Requests\BusinessManagement\Customer;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Un usuario acotado a su cartera asignada es solo-lectura en Clientes.
        return empty($this->user()?->assignedCustomerIds())
            && ($this->user()?->can('customers.edit') ?? false);
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('customers.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // name aceptado como sometimes (cliente puede mandar solo is_active),
            // pero si viene, NO puede ser empty string ni null. Sin min:1 antes
            // un cliente podía mandar name:"" y el customer quedaba sin nombre
            // (rompía unicidad y búsqueda).
            'changes.*.name'      => 'sometimes|required|string|min:1|max:255',
            'changes.*.is_active' => 'sometimes|nullable|boolean',
        ];
    }

    /**
     * El nombre es unico por workspace, y en «Editar todo» eso no lo cubria
     * nadie: la pantalla solo detecta repetidos DENTRO de la pagina que se ve.
     * Renombrar una fila con un nombre que ya existe en otra pagina llegaba
     * hasta el indice unico de Postgres y salia un 500 en la cara del usuario,
     * con todo el lote perdido. Aqui se corta antes, y se dice en que fila.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $changes = $this->input('changes');
            if (!is_array($changes)) return;

            $tenantId = auth()->user()?->tenant_id;
            $isPgsql  = \Illuminate\Support\Facades\DB::getDriverName() === 'pgsql';
            $vistos   = [];

            foreach ($changes as $i => $c) {
                if (!is_array($c) || !isset($c['name'])) continue;
                $nombre = trim((string) $c['name']);
                if ($nombre === '') continue;

                // Repetido dentro del propio lote.
                $clave = mb_strtolower($nombre);
                if (isset($vistos[$clave])) {
                    $v->errors()->add("changes.{$i}.name", __('customers.name_duplicate_in_batch'));
                    continue;
                }
                $vistos[$clave] = true;

                // Choca con una fila que no viene en el lote.
                $q = \Illuminate\Support\Facades\DB::table('customers')
                    ->whereNull('deleted_at')
                    ->where('tenant_id', $tenantId)
                    ->whereNotIn('id', array_filter(array_column($changes, 'id')));
                if ($isPgsql) {
                    $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$nombre]);
                } else {
                    $q->whereRaw('LOWER(name) = LOWER(?)', [$nombre]);
                }
                if ($q->exists()) {
                    $v->errors()->add("changes.{$i}.name", __('customers.name_unique'));
                }
            }
        });
    }
}
