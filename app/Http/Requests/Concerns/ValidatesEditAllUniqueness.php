<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * La unicidad en «Editar todo», que no la comprobaba nadie.
 *
 * La pantalla detecta valores repetidos **dentro de la pagina que se ve**, y
 * ahi se acaba. Renombrar una fila con un valor que ya existe en otra pagina
 * —o en una fila que no cabe en el lote— llegaba tal cual al indice unico de
 * Postgres: 500 en la cara del usuario y **el lote entero perdido**, que
 * pueden ser doscientas filas de trabajo.
 *
 * Se corta aqui, y se dice EN QUE FILA. Dos casos distintos y dos mensajes:
 * repetido dentro del propio lote, o choca con algo que esta fuera de el.
 *
 * Va en un trait y no copiado en cada request porque el fallo estaba en nueve
 * modulos a la vez: es el molde el que venia sin la comprobacion, y copiarla
 * nueve veces garantiza que el decimo tampoco la tenga.
 *
 * Que la comparacion sea sin tildes y sin mayusculas no es un detalle: tiene
 * que decir lo MISMO que el indice de la base. Si aqui se compara mas flojo,
 * el 500 vuelve; si se compara mas estricto, se rechazan valores que la base
 * habria aceptado. Los indices de verdad, comprobados contra el motor, usan
 * `unaccent_immutable(lower(...))` — de ahi la forma exacta de abajo.
 */
trait ValidatesEditAllUniqueness
{
    /**
     * @param  array<int, array{
     *     campo: string,
     *     tabla: string,
     *     columna?: string,
     *     mensaje: string,
     *     ambito?: 'global'|'workspace',
     *     tildes?: bool,
     *     borrados?: bool,
     *     extra?: \Closure,
     * }>  $reglas
     */
    protected function comprobarUnicidadDelLote(Validator $validator, array $reglas): void
    {
        $cambios = $this->input('changes');

        if (! is_array($cambios)) {
            return;
        }

        // Los ids del propio lote: una fila no choca consigo misma, ni con otra
        // que se esta renombrando en la misma tanda (para eso esta el control
        // de repetidos dentro del lote, que si distingue el orden).
        $idsDelLote = array_values(array_filter(array_map(
            fn ($c) => is_array($c) && isset($c['id']) ? (int) $c['id'] : null,
            $cambios,
        )));

        foreach ($reglas as $regla) {
            $this->unaRegla($validator, $cambios, $idsDelLote, $regla);
        }
    }

    /**
     * A que workspace pertenece cada fila del lote.
     *
     * El ambito NO se saca de quien edita, se saca de la fila. El indice dice
     * `COALESCE(tenant_id, 0)`, o sea que un perfil de la empresa 1 llamado
     * «Editor» y uno global llamado «Editor» **no chocan**: son cubos
     * distintos. Comparando contra «los mios mas los globales» —que es lo
     * primero que uno escribe— se rechazarian nombres que la base acepta, y
     * eso es tan malo como el 500: el usuario no puede guardar y no entiende
     * por que. Ademas un super edita filas que no son de su propio workspace.
     *
     * Una consulta para todo el lote, no una por fila.
     *
     * @param  array<int, int>  $ids
     * @return array<int, int|null>
     */
    private function workspaceDeCadaFila(string $tabla, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($tabla)->whereIn('id', $ids)->pluck('tenant_id', 'id')
            ->map(fn ($t) => $t === null ? null : (int) $t)
            ->all();
    }

    /**
     * @param  array<int, mixed>  $cambios
     * @param  array<int, int>  $idsDelLote
     * @param  array<string, mixed>  $regla
     */
    private function unaRegla(Validator $validator, array $cambios, array $idsDelLote, array $regla): void
    {
        $campo  = $regla['campo'];
        $vistos = [];

        $porWorkspace = ($regla['ambito'] ?? 'global') === 'workspace';
        $workspaces   = $porWorkspace ? $this->workspaceDeCadaFila($regla['tabla'], $idsDelLote) : [];

        foreach ($cambios as $i => $c) {
            if (! is_array($c) || ! isset($c[$campo])) {
                continue;
            }

            $valor = trim((string) $c[$campo]);

            // Vacio no es un choque: de eso se encarga la regla `required` del
            // campo, y avisar dos veces de lo mismo en la misma fila confunde.
            if ($valor === '') {
                continue;
            }

            $clave = $this->normalizar($valor, $regla['tildes'] ?? true);

            if (isset($vistos[$clave])) {
                $validator->errors()->add(
                    "changes.{$i}.{$campo}",
                    __('global.value_repeated_in_batch', ['row' => $vistos[$clave] + 1]),
                );

                continue;
            }

            $vistos[$clave] = $i;

            $workspace = $porWorkspace ? ($workspaces[(int) ($c['id'] ?? 0)] ?? false) : false;

            if ($this->chocaFueraDelLote($valor, $idsDelLote, $regla, $workspace)) {
                $validator->errors()->add("changes.{$i}.{$campo}", __($regla['mensaje']));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $regla
     * @param  int|null|false  $workspace  el de la fila; `false` = ambito global
     */
    private function chocaFueraDelLote(string $valor, array $idsDelLote, array $regla, int|null|false $workspace): bool
    {
        $columna = $regla['columna'] ?? $regla['campo'];
        $tildes  = $regla['tildes'] ?? true;

        $q = DB::table($regla['tabla']);

        // `roles` es la excepcion: su indice unico NO filtra por `deleted_at`,
        // asi que una fila en la papelera sigue reservando el nombre. Filtrar
        // aqui haria pasar la validacion y reventar la base igualmente.
        if ($regla['borrados'] ?? true) {
            $q->whereNull('deleted_at');
        }

        if ($idsDelLote !== []) {
            $q->whereNotIn('id', $idsDelLote);
        }

        // Ambito `global` (catalogos de plataforma: el nombre es unico para
        // todo el mundo) o el cubo de la propia fila, que es lo que dice
        // `COALESCE(tenant_id, 0)` en el indice.
        if ($workspace !== false) {
            $workspace === null
                ? $q->whereNull('tenant_id')
                : $q->where('tenant_id', $workspace);
        }

        if (isset($regla['extra']) && $regla['extra'] instanceof \Closure) {
            ($regla['extra'])($q);
        }

        if (DB::getDriverName() === 'pgsql' && $tildes) {
            $q->whereRaw(
                "unaccent_immutable(LOWER({$columna})) = unaccent_immutable(LOWER(?))",
                [$valor],
            );
        } else {
            $q->whereRaw("LOWER({$columna}) = LOWER(?)", [$valor]);
        }

        return $q->exists();
    }

    /** La misma normalizacion, para comparar filas del lote entre si. */
    private function normalizar(string $valor, bool $tildes): string
    {
        $v = mb_strtolower($valor);

        if (! $tildes) {
            return $v;
        }

        return strtr($v, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);
    }
}
