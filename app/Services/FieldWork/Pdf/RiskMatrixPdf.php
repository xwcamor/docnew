<?php

namespace App\Services\FieldWork\Pdf;

use App\Models\FormAnswer;
use App\Models\FormField;
use Illuminate\Support\Collection;

/**
 * La matriz de riesgo del AST y del PTF, resuelta para el papel.
 *
 * QUE ESTABA MAL. El PDF sacaba este campo por el serializador generico de
 * `FormSubmissionPdfService::tabla()`, que deriva las columnas de las claves
 * del JSON guardado. Para un texto libre eso vale; para la matriz salia una
 * tabla plana con las cabeceras «Actividad, Peligro, Riesgo, Control,
 * Severidad, Probabilidad, Valor riesgo, Nivel» —las claves crudas, en el
 * orden en que las escribio la pantalla, con «c3» y «p2» de contenido— y la
 * actividad repetida en cada una de las quince filas. El dueño del producto lo
 * rechazo, y con razon: su AST de papel no es una tabla plana, es un bloque por
 * actividad con sus peligros dentro. Asi esta en la v1
 * (`f1_document_activities` → `f1_document_dangers`) y asi lo pinta ya la
 * pantalla de llenado (`RiskMatrixField.vue`).
 *
 * Lo que resuelve esta clase, y por eso existe aparte del serializador:
 *
 *  1. AGRUPA por tramos contiguos de `actividad`, exactamente igual que el
 *     componente Vue. Contiguos y no por texto: el orden de las filas ES el
 *     orden del documento, y dos actividades distintas de una jornada pueden
 *     llamarse igual. Reagrupar por nombre cambiaria el AST sin que nadie lo
 *     pidiera.
 *  2. TRADUCE las claves internas. Lo guardado es `c1..c5` / `p1..p5` —es lo
 *     que indexa `config.matrix` por posicion y lo que traen las 3 657
 *     entregas migradas—, pero la v1 nunca enseño eso: pintaba la traduccion
 *     que escribio el administrador («Catastrófico», «Podría suceder»). Esos
 *     nombres llegan en `config.severity_labels` / `probability_labels` (y sus
 *     `_en`). Si no llegan, se cae a la clave, que es lo que habia.
 *  3. DEDUCE valor y nivel cuando la fila no los trae. Es el caso de todo lo
 *     migrado: la v1 guardaba `risk_value` y calculaba la banda al pintar
 *     (`Risk#level_name`), asi que esas filas tienen el numero y no la palabra.
 *     Sin esto un AST migrado salia impreso con las ocho evaluaciones en
 *     blanco — en un documento de seguridad eso dice que nadie evaluo los
 *     peligros de esa jornada, y es mentira.
 *
 * Nada de lo que se deduce aqui se guarda: el nivel es un derivado del valor y
 * de las bandas de la plantilla, y guardarlo aparte seria tener dos verdades.
 *
 * El texto visible NO sale de aqui: esta clase devuelve claves y datos, y el
 * parcial `pdf/campos/risk_matrix.blade.php` los traduce con
 * `form_submissions.pdf.risk_matrix.*`. Es lo mismo que hace el resto del PDF y
 * permite probar esta salida sin montar el contenedor de traducciones.
 */
final class RiskMatrixPdf
{
    /**
     * Las cinco casillas que hacen un peligro entero.
     *
     * Es la misma regla de `FormSubmissionService::exigirPeligroEntero()` —en
     * cuanto alguien pone severidad o probabilidad, la fila va completa— pero
     * en el orden de columnas de la v1, que pone probabilidad antes que
     * severidad. Aqui no valida nada: solo sirve para decir en el papel que la
     * fila quedo a medias, porque un borrador se puede imprimir y un peligro
     * puntuado «alto» sin decir de que peligro se trata tiene que verse.
     */
    public const COLUMNAS = ['peligro', 'riesgo', 'control', 'probabilidad', 'severidad'];

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array{
     *     actividades: array<int, array>,
     *     total: int, evaluados: int, incompletos: int,
     *     niveles: array<int, array{clave: string, tono: string, cuenta: int}>
     * }
     */
    public static function datos(FormField $campo, Collection $respuestas): array
    {
        $config = $campo->config ?? [];

        $severidades    = self::catalogo($config, 'severities', 'severidades');
        $probabilidades = self::catalogo($config, 'probabilities', 'probabilidades');
        $bandas         = self::bandas($config, count($severidades) * count($probabilidades));

        $matriz = [
            'severidades'    => $severidades,
            'probabilidades' => $probabilidades,
            'valores'        => is_array($config['matrix'] ?? null) ? $config['matrix'] : null,
        ];

        $rotulos = [
            'severidad'    => self::rotulos($config, 'severity_labels'),
            'probabilidad' => self::rotulos($config, 'probability_labels'),
        ];

        $actividades = [];

        foreach (self::filas($respuestas) as $cruda) {
            $actividad = self::texto($cruda['actividad'] ?? null);
            $ultima    = $actividades === [] ? null : array_key_last($actividades);

            // El tramo se corta cuando cambia el nombre. Una actividad SIN
            // peligros abre igualmente su bloque: en la v1 es una
            // `F1DocumentActivity` sin `f1_document_dangers`, es una fila
            // legitima y la migracion las conserva. Que no aparezca en el
            // papel seria perder una actividad declarada.
            if ($ultima === null || $actividades[$ultima]['actividad'] !== $actividad) {
                $actividades[] = [
                    'numero'    => count($actividades) + 1,
                    'actividad' => $actividad,
                    'peligros'  => [],
                ];
                $ultima = array_key_last($actividades);
            }

            $peligro = self::peligro($cruda, $matriz, $bandas, $rotulos);

            if ($peligro !== null) {
                $actividades[$ultima]['peligros'][] = $peligro;
            }
        }

        $actividades = array_map(fn (array $a) => $a + self::resumen($a['peligros'], $bandas), $actividades);

        return [
            'actividades' => $actividades,
            'total'       => array_sum(array_column($actividades, 'total')),
            'evaluados'   => array_sum(array_column($actividades, 'evaluados')),
            'incompletos' => array_sum(array_column($actividades, 'incompletos')),
            'niveles'     => self::conteoPorNivel($actividades, $bandas),
        ];
    }

    // ── Filas guardadas ─────────────────────────────────────────────────────

    /**
     * Las filas del campo en el orden del documento.
     *
     * Cada peligro es una respuesta propia con su `row_index`
     * (`FormSubmissionService::responder()`), y ese indice es el que ordena el
     * AST: la agrupacion por tramos contiguos depende de el, asi que ordenar
     * mal aqui parte una actividad en dos bloques con el mismo nombre. Se
     * desempata por `id` porque un `row_index` duplicado —dos importaciones
     * sobre la misma entrega— dejaria el orden a merced del motor.
     *
     * Se tolera que una respuesta traiga la lista entera en vez de una fila:
     * los volcados de `docufiz:migrate-formats` anteriores al reparto por
     * `row_index` estan asi, y perder esos AST al imprimir no es una opcion.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array<int, array<string, mixed>>
     */
    protected static function filas(Collection $respuestas): array
    {
        $filas = [];

        $ordenadas = $respuestas->sortBy(
            fn (FormAnswer $r) => [(int) $r->row_index, (int) $r->getKey()],
        );

        foreach ($ordenadas as $respuesta) {
            $valor = $respuesta->value_json;

            if (! is_array($valor)) {
                continue;
            }

            if (array_is_list($valor)) {
                foreach ($valor as $fila) {
                    if (is_array($fila) && ! array_is_list($fila)) {
                        $filas[] = $fila;
                    }
                }

                continue;
            }

            $filas[] = $valor;
        }

        return $filas;
    }

    /**
     * Un peligro listo para imprimir, o null si la fila solo abre la actividad.
     *
     * La fila vacia con nombre de actividad es la que emite `filaVacia()` al
     * añadir una actividad y la que trae la migracion para las actividades sin
     * peligros. Pintarla como un peligro daria una fila de guiones en el papel
     * diciendo que hay un peligro sin identificar, que es peor que no decir
     * nada: el bloque ya avisa de que la actividad no registro peligros.
     */
    protected static function peligro(array $fila, array $matriz, array $bandas, array $rotulos): ?array
    {
        $celdas = [];

        foreach (self::COLUMNAS as $columna) {
            $celdas[$columna] = self::texto($fila[$columna] ?? null);
        }

        $valor = self::valor($fila, $matriz);

        if (count(array_filter($celdas)) === 0 && $valor === null) {
            return null;
        }

        $banda = self::banda($fila['nivel'] ?? null, $valor, $bandas);

        return [
            'peligro' => $celdas['peligro'],
            'riesgo'  => $celdas['riesgo'],
            'control' => $celdas['control'],
            // La clave sigue viajando al lado del nombre: cuando el mapa de
            // rotulos no llego, «c3» impreso es lo unico que hay, y cuando si
            // llego sirve para cotejar el papel con la tabla de riesgos.
            'probabilidad'       => $rotulos['probabilidad'][$celdas['probabilidad']] ?? $celdas['probabilidad'],
            'probabilidad_clave' => $celdas['probabilidad'],
            'severidad'          => $rotulos['severidad'][$celdas['severidad']] ?? $celdas['severidad'],
            'severidad_clave'    => $celdas['severidad'],
            'valor'              => $valor,
            'nivel'              => $banda['clave'] ?? null,
            'tono'               => $banda['tono'] ?? null,
            'faltan'             => self::faltan($celdas),
        ];
    }

    /**
     * El numero de la matriz: el guardado, y si no, el de la tabla.
     *
     * No es severidad × probabilidad. La matriz de la v1 es un ranking del 1 al
     * 25 donde el 1 es lo peor, y doce de las veinticinco celdas caen en otra
     * banda si se multiplica: c2×p4 vale 12 en la tabla y 8 en el producto, que
     * es la diferencia entre «medio» y «alto» en un documento que puede acabar
     * delante de un inspector. El producto queda solo de red, para un formato
     * nuevo que alguien defina sin `matrix`.
     */
    protected static function valor(array $fila, array $matriz): ?int
    {
        $guardado = $fila['valor_riesgo'] ?? null;

        if (is_numeric($guardado)) {
            return (int) $guardado;
        }

        $s = array_search(self::texto($fila['severidad'] ?? null), $matriz['severidades'], true);
        $p = array_search(self::texto($fila['probabilidad'] ?? null), $matriz['probabilidades'], true);

        if ($s === false || $p === false) {
            return null;
        }

        $tabla = $matriz['valores'];

        return isset($tabla[$s][$p]) && is_numeric($tabla[$s][$p])
            ? (int) $tabla[$s][$p]
            : ($s + 1) * ($p + 1);
    }

    /** La banda de la fila: la escrita si la trae, y si no la que le toca por valor. */
    protected static function banda(mixed $nivel, ?int $valor, array $bandas): ?array
    {
        $clave = self::texto($nivel);

        if ($clave !== null) {
            foreach ($bandas as $banda) {
                if ($banda['clave'] === $clave) {
                    return $banda;
                }
            }

            // Una banda que la plantilla ya no define —se reconfiguro despues
            // de firmar— se imprime igual con su palabra y sin color: el
            // documento congelado dijo «alto» y el papel tiene que decir
            // «alto», aunque hoy esa banda no exista.
            return ['clave' => $clave, 'tono' => 'off'];
        }

        if ($valor === null) {
            return null;
        }

        foreach ($bandas as $banda) {
            if ($valor <= $banda['hasta']) {
                return $banda;
            }
        }

        return $bandas === [] ? null : $bandas[array_key_last($bandas)];
    }

    /** Las columnas que le faltan a una fila ya empezada a puntuar. */
    protected static function faltan(array $celdas): array
    {
        if ($celdas['severidad'] === null && $celdas['probabilidad'] === null) {
            return [];
        }

        return array_values(array_filter(
            self::COLUMNAS,
            fn (string $columna) => $celdas[$columna] === null,
        ));
    }

    // ── Resumen ─────────────────────────────────────────────────────────────

    /**
     * El resumen de una actividad, que es lo que se lee de un vistazo.
     *
     * Un AST no se abre para contar peligros: se abre para saber que actividad
     * trae el alto. La v1 no lo dice en ningun sitio y hay que recorrer las
     * veinticinco filas de la columna «Riesgo/Impacto» comparando numeros
     * —donde ademas el 6 es peor que el 20, que no lo adivina nadie—. La
     * pantalla de llenado ya pone esa banda peor en la cabecera de cada
     * actividad; el papel dice lo mismo.
     */
    protected static function resumen(array $peligros, array $bandas): array
    {
        $peor = null;

        foreach ($peligros as $peligro) {
            if ($peligro['nivel'] === null) {
                continue;
            }

            $posicion = self::posicion($peligro['nivel'], $bandas);

            if ($peor === null || $posicion < self::posicion($peor['nivel'], $bandas)) {
                $peor = $peligro;
            }
        }

        return [
            'total'      => count($peligros),
            'evaluados'  => count(array_filter($peligros, fn ($p) => $p['nivel'] !== null)),
            'incompletos' => count(array_filter($peligros, fn ($p) => $p['faltan'] !== [])),
            'nivel_peor' => $peor['nivel'] ?? null,
            'tono_peor'  => $peor['tono'] ?? null,
        ];
    }

    /**
     * Cuantos peligros hay en cada banda, de peor a mejor.
     *
     * Es una linea sola —«2 alto · 5 medio · 1 bajo»— y es lo primero que mira
     * el supervisor que firma. Solo salen las bandas con peligros: un «0 bajo»
     * ocupa sitio y no dice nada.
     */
    protected static function conteoPorNivel(array $actividades, array $bandas): array
    {
        $cuentas = [];

        foreach ($actividades as $actividad) {
            foreach ($actividad['peligros'] as $peligro) {
                if ($peligro['nivel'] !== null) {
                    $cuentas[$peligro['nivel']] = ($cuentas[$peligro['nivel']] ?? 0) + 1;
                }
            }
        }

        uksort($cuentas, fn ($a, $b) => self::posicion($a, $bandas) <=> self::posicion($b, $bandas));

        $salida = [];

        foreach ($cuentas as $clave => $cuenta) {
            $salida[] = [
                'clave'  => $clave,
                'tono'   => self::tonoDe($clave, $bandas),
                'cuenta' => $cuenta,
            ];
        }

        return $salida;
    }

    /** Posicion de una banda en la lista, que va de peor a mejor. Lo desconocido, al final. */
    protected static function posicion(?string $clave, array $bandas): int
    {
        foreach ($bandas as $indice => $banda) {
            if ($banda['clave'] === $clave) {
                return $indice;
            }
        }

        return count($bandas);
    }

    protected static function tonoDe(?string $clave, array $bandas): string
    {
        foreach ($bandas as $banda) {
            if ($banda['clave'] === $clave) {
                return $banda['tono'];
            }
        }

        return 'off';
    }

    // ── Configuracion del campo ─────────────────────────────────────────────

    /**
     * Las bandas de riesgo, de peor a mejor, con el tono con el que se pintan.
     *
     * Vienen en `config.levels` —1-8 alto, 9-15 medio, 16-25 bajo, que es
     * `Risk#level_name` del sistema anterior— y se traen enteras para no
     * escribir aqui una segunda lista de niveles que se desincronice.
     *
     * El TONO se deriva de la posicion y no del nombre de la banda a proposito.
     * El motor de formatos deja definir las bandas desde la interfaz, asi que
     * un `switch ('alto')` en la plantilla Blade dejaria en gris cualquier
     * formato que no use esas tres palabras — justo el caso en el que el color
     * mas falta hace. La primera banda es la peor, la ultima la mejor, y lo de
     * en medio avisa.
     */
    protected static function bandas(array $config, int $maximo): array
    {
        $crudas = $config['levels'] ?? null;

        if (! is_array($crudas) || $crudas === []) {
            // Sin bandas definidas se reparte en tercios, igual que la pantalla.
            // Es un formato nuevo sin matriz configurada: no hay nombres que
            // usar, y «alto/medio/bajo» son los del dominio.
            $crudas = $maximo > 0
                ? [
                    ['hasta' => (int) ceil($maximo / 3),     'clave' => 'alto'],
                    ['hasta' => (int) ceil($maximo * 2 / 3), 'clave' => 'medio'],
                    ['hasta' => $maximo,                     'clave' => 'bajo'],
                ]
                : [];
        }

        $bandas = [];
        $total  = count($crudas);

        foreach (array_values($crudas) as $indice => $banda) {
            $clave = self::texto($banda['clave'] ?? null);

            if ($clave === null) {
                continue;
            }

            $bandas[] = [
                'clave' => $clave,
                'hasta' => (int) ($banda['hasta'] ?? PHP_INT_MAX),
                'tono'  => match (true) {
                    $total < 2, $indice === 0 => 'bad',
                    $indice === $total - 1    => 'ok',
                    default                   => 'warn',
                },
            ];
        }

        return $bandas;
    }

    /**
     * Los nombres con los que se enseñan las claves internas, en el idioma del
     * documento.
     *
     * El es va de base y el en encima cuando el PDF sale en ingles: una clave
     * que el administrador no tradujo cae al texto en español, que sigue
     * diciendo mucho mas que un «c3» pelado. Es la misma mezcla que hace
     * `RiskMatrixField.vue`, y tiene que serlo: el papel y la pantalla no
     * pueden llamar de dos maneras distintas a la misma severidad.
     *
     * Ojo con el idioma: aqui ya manda el del PAIS DEL PLAN, que es el que fija
     * `FormSubmissionPdfService::generar()` antes de renderizar, y no el de
     * quien pulsa el boton.
     */
    protected static function rotulos(array $config, string $base): array
    {
        $es = $config[$base] ?? [];
        $en = app()->getLocale() === 'en' ? ($config["{$base}_en"] ?? []) : [];

        return array_filter(
            array_merge(is_array($es) ? $es : [], is_array($en) ? $en : []),
            fn ($valor) => is_string($valor) && trim($valor) !== '',
        );
    }

    /**
     * Un catalogo de la config como lista de textos.
     *
     * Tolera que venga un numero en vez de la lista porque el seeder de
     * demostracion guarda `['severidades' => 5]`, igual que `catalogo()` en
     * `respuestas.js`: si aqui se leyera distinto, la posicion con la que se
     * indexa `config.matrix` no seria la misma que uso la pantalla al guardar.
     */
    protected static function catalogo(array $config, string ...$claves): array
    {
        foreach ($claves as $clave) {
            $valor = $config[$clave] ?? null;

            if (is_array($valor)) {
                return array_values(array_map(
                    fn ($v) => (string) $v,
                    array_filter($valor, fn ($v) => $v !== null && $v !== ''),
                ));
            }

            if (is_int($valor) && $valor > 0) {
                return array_map('strval', range(1, $valor));
            }
        }

        return [];
    }

    /** Texto util o null: el papel no distingue entre «no puesto» y «puesto en blanco». */
    protected static function texto(mixed $valor): ?string
    {
        if ($valor === null || is_array($valor) || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
