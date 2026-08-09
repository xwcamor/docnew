<?php

namespace App\Http\Requests\BusinessManagement\Brand;

use Illuminate\Foundation\Http\FormRequest;

class EditAllUpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('brands.edit') ?? false;
    }

    public function rules(): array
    {
        // edit_all_max define cuantas filas se pueden tocar en un solo batch.
        $max = (int) config('brands.edit_all_max', 200);

        return [
            'changes'             => "required|array|min:1|max:{$max}",
            'changes.*.id'        => 'required|integer',
            // name aceptado como sometimes (el cliente puede mandar solo
            // is_active), pero si viene NO puede ser cadena vacia ni null: sin
            // min:1 la marca se quedaba sin nombre y rompia unicidad y busqueda.
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
                    $v->errors()->add("changes.{$i}.name", __('brands.name_duplicate_in_batch'));
                    continue;
                }
                $vistos[$clave] = true;

                // Choca con una fila que no viene en el lote.
                $q = \Illuminate\Support\Facades\DB::table('brands')
                    ->whereNull('deleted_at')
                    ->where('tenant_id', $tenantId)
                    ->whereNotIn('id', array_filter(array_column($changes, 'id')));
                if ($isPgsql) {
                    $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$nombre]);
                } else {
                    $q->whereRaw('LOWER(name) = LOWER(?)', [$nombre]);
                }
                if ($q->exists()) {
                    $v->errors()->add("changes.{$i}.name", __('brands.name_unique'));
                }
            }
        });
    }
}
