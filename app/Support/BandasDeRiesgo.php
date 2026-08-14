<?php

namespace App\Support;

/**
 * Las bandas de una matriz de riesgo: en cual cae un valor y como se pinta.
 *
 * DE DONDE VIENE ESTO Y QUE SE ROMPIA
 * -----------------------------------
 * La matriz de la v1 es un ranking del 1 al 25 donde el 1 es lo peor, con tres
 * bandas: `1-8 alto`, `9-15 medio`, `16-25 bajo` (`Risk#level_name`). El motor
 * ya la leia de `config.levels` en vez de tenerla cableada, que estaba bien, y
 * hasta ahi llegaba: la CLAVE de cada banda se usaba ademas como clave de
 * traduccion (`field_work.risk_matrix.level_alto`) y como clase de CSS
 * (`.ff-risk.is-alto`). O sea que las bandas eran configurables **siempre que se
 * llamaran alto, medio y bajo**. Una empresa con `critico / moderado /
 * aceptable` veia la palabra «critico» en crudo y sin ningun color.
 *
 * Y el reparto se resolvia con un unico `hasta` acumulativo, lo que da por
 * supuesto que el numero pequeño es el peor. Es verdad en la matriz de la v1 y
 * es FALSO en la matriz clasica de severidad × probabilidad, donde 25 es lo
 * peor. Ahi las tres bandas salian del reves —lo critico en verde— y nadie se
 * enteraba hasta leer un documento firmado.
 *
 * LA FORMA DE UNA BANDA
 * ---------------------
 *     {"clave": "critico", "min": 20, "max": 25,
 *      "label": {"es": "Riesgo crítico", "en": "Critical risk"},
 *      "tone": "bad", "tolerable": false}
 *
 * `clave` es lo que se GUARDA en la respuesta y no se traduce nunca: es lo que
 * escribieron los 3 657 AST migrados. `label` es lo unico que se lee. `tone` es
 * como se pinta, de la lista corta de tonos del sistema, y no un color a pelo:
 * un `#c00` en la config no sabe volverse legible en modo oscuro ni en una
 * fotocopia, y ademas se separaria del resto de la interfaz al primer cambio de
 * paleta.
 *
 * `min`/`max` son un RANGO, no un limite acumulado, y con eso la direccion de
 * la escala deja de importar: da igual que lo peor sea el 1 o el 25.
 *
 * LO VIEJO SIGUE VALIENDO, SIN TOCAR UNA FILA. Una banda con solo `hasta` se lee
 * como el tramo que va desde donde acabo la anterior: exactamente lo que hacia
 * antes. Y sin `tone`, el reparto automatico pinta la primera de rojo, la
 * ultima de verde y las de en medio de ambar, que es justo como quedaban
 * alto/medio/bajo.
 */
class BandasDeRiesgo
{
    /**
     * Los tonos que una banda puede declarar.
     *
     * Son los `--state-*` de la hoja de estilos, ni uno mas: es lo que hace que
     * el rojo de una banda y el rojo de una no conformidad sean el mismo rojo.
     */
    public const TONOS = ['bad', 'warn', 'ok', 'info', 'off'];

    /**
     * Las bandas de un `config`, normalizadas.
     *
     * @return array<int, array{clave: string, min: int, max: int, label: string, tone: string, tolerable: bool}>
     */
    public static function de(array $config, ?string $locale = null): array
    {
        return self::normalizar($config['levels'] ?? $config['niveles'] ?? null, $locale);
    }

    /**
     * Una lista de bandas, venga como venga, en la forma de arriba.
     *
     * Es IDEMPOTENTE, y lo es POR CONSTRUCCION y no por un atajo: `nivelDeRiesgo()`
     * es publico y lleva años recibiendo la lista cruda de `config.levels` desde
     * el migrador y desde las pruebas, asi que volver a pasar por aqui algo ya
     * normalizado tiene que devolverlo igual.
     *
     * AQUI HUBO UN ATAJO QUE ESTABA MAL Y CONVIENE DEJARLO ESCRITO. La primera
     * version detectaba «ya normalizada» mirando si la banda traia `min` y `max`
     * y, en ese caso, la devolvia tal cual. Pero una banda ESCRITA ASI EN LA
     * CONFIG —que es justo la forma nueva que vinimos a soportar— tiene `min` y
     * `max` y no esta normalizada: se saltaba la traduccion del rotulo, y una
     * banda con `label: {es: …, en: …}` salia con el mapa entero donde iba el
     * texto. Sin atajo no hace falta adivinar nada: pasar dos veces da lo mismo
     * porque cada paso es estable —una cadena traducida vuelve a traducirse a si
     * misma, un tono valido se conserva, un rango explicito se respeta.
     *
     * @return array<int, array{clave: string, min: int, max: int, label: string, tone: string, tolerable: bool}>
     */
    public static function normalizar(mixed $crudas, ?string $locale = null): array
    {
        if (! is_array($crudas) || $crudas === []) {
            return [];
        }

        $bandas = [];
        // Para la forma vieja: una banda con solo `hasta` empieza donde acabo la
        // anterior. La primera empieza en 1, que es el menor valor de una matriz.
        $desde = 1;

        foreach ($crudas as $cruda) {
            if (! is_array($cruda)) {
                continue;
            }

            $clave = $cruda['clave'] ?? $cruda['key'] ?? null;

            if (! is_scalar($clave) || (string) $clave === '') {
                continue;
            }

            $hasta = $cruda['max'] ?? $cruda['hasta'] ?? null;
            $min   = $cruda['min'] ?? null;

            if (! is_numeric($hasta)) {
                continue;
            }

            $max = (int) $hasta;
            $min = is_numeric($min) ? (int) $min : $desde;

            // Un rango escrito al reves se endereza en vez de no casar con
            // nada: quien lo escribio queria ese tramo, no un agujero.
            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            $bandas[] = [
                'clave'     => (string) $clave,
                'min'       => $min,
                'max'       => $max,
                'label'     => TextoTraducible::de($cruda['label'] ?? null, $locale),
                'tone'      => in_array($cruda['tone'] ?? null, self::TONOS, true) ? $cruda['tone'] : null,
                'tolerable' => ($cruda['tolerable'] ?? null) === true,
            ];

            $desde = $max + 1;
        }

        return self::conLosHuecosResueltos($bandas);
    }

    /**
     * En que banda cae un valor.
     *
     * Gana la PRIMERA que lo contenga. Con rangos escritos a mano puede haber
     * solapes, y en un documento de seguridad es mejor una regla que siempre da
     * el mismo resultado que una que intenta ser lista.
     *
     * @param  array<int, array>  $bandas  ya normalizadas por `de()`
     */
    public static function deValor(mixed $valor, array $bandas): ?array
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $bandas = self::normalizar($bandas);
        $valor  = (int) $valor;

        // El 0 y los negativos no son puntuaciones de una matriz: son el hueco
        // de una fila sin evaluar, y eso lo dice `faltantes()`, no una banda.
        if ($valor <= 0) {
            return null;
        }

        foreach ($bandas as $banda) {
            if ($valor >= $banda['min'] && $valor <= $banda['max']) {
                return $banda;
            }
        }

        return null;
    }

    /**
     * La banda que NO cuenta como observacion.
     *
     * Marcada con `tolerable: true`; si nadie la marca, la ultima, que es la
     * convencion de las cuatro plantillas migradas (van de peor a mejor). Se
     * devuelve la clave porque es lo que se compara con lo guardado.
     */
    public static function tolerable(array $bandas): ?string
    {
        $bandas = self::normalizar($bandas);

        foreach ($bandas as $banda) {
            if ($banda['tolerable'] ?? false) {
                return $banda['clave'];
            }
        }

        return $bandas === [] ? null : (end($bandas)['clave'] ?? null);
    }

    /**
     * Rellena rotulo y tono de las bandas que no los declaran.
     *
     * EL TONO, por posicion: la primera en rojo, la ultima en verde y las de en
     * medio en ambar. Sale de la convencion «se declaran de peor a mejor», que
     * es como vienen las cuatro plantillas migradas, y da exactamente
     * alto/medio/bajo para ellas. Es un valor por defecto util, no una regla: en
     * cuanto una banda declara su `tone`, manda el suyo.
     *
     * EL ROTULO cae a la traduccion del producto (`level_alto`, `level_medio`,
     * `level_bajo`) para que las plantillas migradas sigan leyendose igual sin
     * tener que reescribirles la config, y a la clave pelada si tampoco existe
     * —que dice menos, pero dice.
     */
    protected static function conLosHuecosResueltos(array $bandas): array
    {
        $ultima = count($bandas) - 1;

        foreach ($bandas as $i => $banda) {
            if ($banda['tone'] === null) {
                $bandas[$i]['tone'] = match (true) {
                    $ultima === 0 => 'info',   // banda unica: no hay peor ni mejor
                    $i === 0      => 'bad',
                    $i === $ultima => 'ok',
                    default       => 'warn',
                };
            }

            if ($banda['label'] === '') {
                $clave = "field_work.risk_matrix.level_{$banda['clave']}";
                $texto = __($clave);

                $bandas[$i]['label'] = is_string($texto) && $texto !== $clave ? $texto : $banda['clave'];
            }
        }

        return $bandas;
    }
}
