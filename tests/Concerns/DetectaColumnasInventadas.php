<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caza las columnas que no existen, que en SQLite pasan sin decir nada.
 *
 * Las pruebas corren sobre SQLite en memoria y producción sobre PostgreSQL, y
 * en una cosa concreta no se comportan igual: **SQLite acepta las comillas
 * dobles como si fueran comillas simples** cuando lo de dentro no es una
 * columna. Eloquent entrecomilla todos los identificadores, así que
 *
 *     select "id", "code", "name_es" from "work_types"
 *
 * en SQLite devuelve una columna llamada `name_es` con el texto «name_es»
 * dentro, tan contento, y en PostgreSQL revienta con un 42703.
 *
 * Es lo que pasó con el panel: `DashboardController` precargaba
 * `workType:id,code,name_es,name_en` —columnas del sistema anterior que aquí no
 * existen—, las 1 212 pruebas seguían en verde y la pantalla se caía en cuanto
 * había un plan con fecha de hoy. Hasta había una prueba que recorría esa misma
 * consulta; el motor se la tragaba.
 *
 * Cómo funciona, y por qué así:
 *
 * 1. El oyente **no toca la base**. Solo mira la cadena SQL y apunta qué
 *    columnas se pidieron de qué tabla. Preguntarle el esquema al motor desde
 *    dentro del oyente es una consulta dentro de una consulta: se lleva por
 *    delante la transacción de `RefreshDatabase` («cannot start a transaction
 *    within a transaction») y tumba media suite.
 * 2. La comprobación se hace al terminar la prueba, cuando ya se puede
 *    preguntar sin estorbar.
 *
 * Solo mira la forma simple —`select "a", "b" from "t"`, que es justo la que
 * genera una precarga con lista de columnas—. Funciones, alias, uniones y
 * subconsultas se dejan pasar: aquí interesa no dar falsas alarmas.
 */
trait DetectaColumnasInventadas
{
    /** Las columnas de cada tabla, para no preguntárselo al motor mil veces. */
    private static array $columnasPorTabla = [];

    /** Lo apuntado durante la prueba: `conexion|tabla => [columna => sql]`. */
    private array $columnasPedidas = [];

    protected function vigilarColumnasInventadas(): void
    {
        $this->columnasPedidas = [];

        DB::listen(function (QueryExecuted $q) {
            // Solo la forma simple. Con una unión, una subconsulta o una
            // función no se sabe de qué tabla es cada columna.
            if (! preg_match('/^select ((?:"[a-z0-9_]+"(?:, )?)+) from "([a-z0-9_]+)"/i', $q->sql, $m)) {
                return;
            }

            preg_match_all('/"([a-z0-9_]+)"/i', $m[1], $columnas);

            // La conexión importa: las pruebas de la migración leen la base de
            // la v1, donde `plans` son los planes de trabajo y no los planes de
            // suscripción del SaaS. Mirando el esquema equivocado, las columnas
            // buenas parecen inventadas.
            foreach ($columnas[1] as $columna) {
                $this->columnasPedidas[$q->connectionName . '|' . $m[2]][strtolower($columna)] = $q->sql;
            }
        });
    }

    /**
     * Las columnas inventadas de esta prueba, ya resueltas contra el esquema.
     *
     * Se separa de la denuncia porque hay que MIRAR antes de que la clase base
     * desmonte la aplicación —después ya no hay conexión que preguntar— pero
     * hay que FALLAR después, o el `tearDown` se corta a la mitad, la
     * transacción de `RefreshDatabase` se queda abierta y la prueba siguiente
     * muere con «cannot start a transaction within a transaction». Así se
     * cayeron 455 de golpe la primera vez.
     *
     * @return array<int, string>
     */
    protected function columnasInventadasDetectadas(): array
    {
        $pedidas = $this->columnasPedidas;
        $this->columnasPedidas = [];

        if ($pedidas === []) {
            return [];
        }

        $inventadas = [];

        foreach ($pedidas as $donde => $columnas) {
            [$conexion, $tabla] = explode('|', $donde, 2);
            $existentes = $this->columnasDe($conexion, $tabla);

            if ($existentes === null) {
                continue;
            }

            foreach ($columnas as $columna => $sql) {
                if (! in_array($columna, $existentes, true)) {
                    $inventadas[] = "  «{$columna}» en «{$tabla}» ({$conexion})\n    {$sql}";
                }
            }
        }

        return $inventadas;
    }

    /** @param array<int, string> $inventadas */
    protected function denunciarColumnasInventadas(array $inventadas): void
    {
        if ($inventadas === []) {
            return;
        }

        $this->fail(
            "La consulta pide columnas que no existen en la tabla:\n\n"
            . implode("\n\n", $inventadas)
            . "\n\nEn SQLite pasa desapercibido —las comillas dobles se leen como texto— pero en "
            . 'PostgreSQL es un error 42703 y la pantalla se cae. Suele venir de una precarga con '
            . "lista de columnas: `->with('relacion:id,columna_que_ya_no_existe')`."
        );
    }

    /** @return array<int, string>|null null si la tabla no existe (o no se puede mirar) */
    private function columnasDe(string $conexion, string $tabla): ?array
    {
        $clave = $conexion . '|' . $tabla;

        if (array_key_exists($clave, self::$columnasPorTabla)) {
            return self::$columnasPorTabla[$clave];
        }

        try {
            $esquema = Schema::connection($conexion);
            $columnas = $esquema->hasTable($tabla)
                ? array_map('strtolower', $esquema->getColumnListing($tabla))
                : null;
        } catch (\Throwable) {
            // Una prueba que deja la conexión a medias no puede convertirse en
            // un fallo de esta vigilancia, que es un extra.
            return null;
        }

        // El «no existe» no se guarda: en la siguiente prueba puede estar.
        if ($columnas !== null) {
            self::$columnasPorTabla[$clave] = $columnas;
        }

        return $columnas;
    }
}
