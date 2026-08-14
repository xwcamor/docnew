<?php

namespace App\Services\FieldWork\Pdf;

use App\Models\FormAnswer;
use App\Models\FormField;
use App\Services\FieldWork\FormFindingsService;
use App\Support\Tz;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * El IHM —inspeccion de herramientas manuales y electricas portatiles, el F4 de
 * la v1— resuelto para imprimirse.
 *
 * El PDF generico volcaba la fila guardada tal cual: una columna «Tool», otra
 * «Items» con el JSON aplanado y, dentro, los `item_id` que solo significan algo
 * dentro de la base de datos. El dueno del producto lo rechazo entero, y con
 * razon: ese papel no se parece al que la cuadrilla lleva firmando anios.
 *
 * El papel de la v1 (`show_pdf_page1.erb`) es una MATRIZ: una fila por
 * herramienta, una columna por punto de inspeccion —con la abreviatura arriba y
 * una leyenda encima que la explica— y, al final, si la herramienta quedo
 * habilitada, la medida de correccion y el responsable. Eso es lo que se
 * reproduce, con dos diferencias deliberadas:
 *
 *  1. Alla la cabecera de cada columna era `ihm_items.short_name` girado 90º.
 *     Aqui los puntos no tienen abreviatura —la plantilla guarda la frase
 *     entera— y DomPDF no gira texto, asi que la columna se numera y el numero
 *     se explica en la leyenda de arriba. Es la misma lectura y ademas no
 *     depende de que alguien mantenga las abreviaturas.
 *  2. La celda lleva la ETIQUETA de la respuesta, no un simbolo. En la v1 eran
 *     `✔`, `-` y `x` con su leyenda aparte, y ademas de que las dos primeras no
 *     existen en las core fonts de DomPDF, un «no cumple» que solo se distingue
 *     por el dibujo se lee mal en una fotocopia. La palabra va escrita y el
 *     color solo la acompana.
 *
 * Lo que sale de aqui NO lleva ni un identificador: `tool_id`, `item_id`,
 * `person_slug` y los `legacy_*` de la migracion se quedan fuera. En el papel no
 * dicen nada y delatan como esta hecha la aplicacion.
 *
 * Se leen las dos formas que hay guardadas de verdad, porque las dos se
 * imprimen igual de a menudo:
 *
 *   - la que emite `ToolChecklistField.vue`:
 *     `{tool, items: [{item, answer}], conforme, correction_measure, responsible}`
 *   - y la que dejo `LegacyFormMapper::checklistDeHerramienta()` en las entregas
 *     migradas, con las claves en castellano y el `item_id` del catalogo viejo:
 *     `{tool, habilitada, medida_correctiva, responsable, items: [{item_id, item, answer}]}`
 */
final class ToolChecklistPdf
{
    /**
     * La verificacion de la correccion, que es la que traba el cierre del plan.
     *
     * `WorkPlanCompletionService::observacionesSinVerificar()` cuenta las filas
     * no conformes que no la traen escrita y con eso impide dar por terminado el
     * plan. El IHM no la declara en `config.extra` —solo el EPP la tiene—, asi
     * que en este formato esta SIEMPRE vacia. No se disimula: en cuanto una
     * herramienta sale no conforme se imprime la linea con su hueco, que es lo
     * que de verdad esta pasando.
     */
    public const VERIFICACION = 'correction_verification';

    /**
     * Claves de la fila que no son una columna de correccion.
     *
     * `tool`/`name` y compania se imprimen en la celda de la herramienta, y
     * `conforme`/`habilitada` se recalculan (ver `estado()`): la v1 marcaba
     * `is_enabled` con un JavaScript a partir de las respuestas, y un dato
     * derivado guardado dos veces acaba contando dos cosas distintas.
     */
    private const ESTRUCTURA = [
        'tool', 'name', 'herramienta', 'items', 'puntos',
        'conforme', 'habilitada', 'is_enabled',
        'code', 'codigo', 'tool_code', 'quantity', 'cantidad',
    ];

    /** Las claves en castellano de la migracion, dichas con el nombre de la plantilla. */
    private const ALIAS = [
        'medida_correctiva'       => 'correction_measure',
        'medida_de_correccion'    => 'correction_measure',
        'responsable'             => 'responsible',
        'fecha_limite'            => 'deadline_date',
        'verificacion'            => self::VERIFICACION,
        'verificacion_correccion' => self::VERIFICACION,
    ];

    /**
     * El orden del papel: que se hizo, para cuando, quien responde y si se
     * comprobo. Lo que no este en la lista se imprime detras, tal como venga.
     */
    private const ORDEN = ['correction_measure', 'deadline_date', 'responsible', self::VERIFICACION];

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array  lo que su parcial necesita, ya resuelto
     */
    public static function datos(FormField $campo, Collection $respuestas): array
    {
        $config = $campo->config ?? [];
        $ordenadas = $respuestas->sortBy('row_index')->values();
        $puntos = self::puntos($config, $ordenadas);

        $filas = [];

        foreach ($ordenadas as $respuesta) {
            foreach (self::comoFilas($respuesta) as $fila) {
                $filas[] = self::herramienta($fila, count($filas) + 1, $puntos, $config);
            }
        }

        return [
            'puntos' => $puntos,
            'filas'  => $filas,
            // Que significa cada marca, con las palabras de esta plantilla. Va
            // encima de la cuadricula: una rejilla de checks y equis sin decir
            // que son es ilegible para quien abre el documento por primera vez.
            // El «?» solo si alguna celda quedo de verdad sin responder.
            'leyenda' => Simbolos::leyenda(
                $config['answers'] ?? [],
                collect($filas)->sum('sin_responder') > 0,
            ),
        ];
    }

    // ── Las columnas ────────────────────────────────────────────────────────

    /**
     * Los puntos de inspeccion que encabezan la matriz, numerados.
     *
     * Manda el orden de la plantilla congelada, que es el del papel. Despues se
     * anaden los puntos que aparezcan en alguna respuesta y no esten declarados:
     * pasa con lo migrado, donde el catalogo de `ihm_items` de hace tres anios
     * no es el de la plantilla de hoy. Perder una respuesta ya dada por no estar
     * en la lista actual seria falsear el documento.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array<int, array{clave: string, numero: int, texto: ?string}>
     */
    private static function puntos(array $config, Collection $respuestas): array
    {
        $textos = [];

        foreach (($config['items'] ?? []) as $punto) {
            if (is_string($punto) && trim($punto) !== '') {
                $textos[trim($punto)] = trim($punto);
            }
        }

        foreach ($respuestas as $respuesta) {
            foreach (self::comoFilas($respuesta) as $fila) {
                foreach (self::items($fila) as $posicion => $item) {
                    [$clave, $texto] = self::identidad($item, $posicion);

                    if (! array_key_exists($clave, $textos)) {
                        $textos[$clave] = $texto;
                    }
                }
            }
        }

        $puntos = [];

        foreach ($textos as $clave => $texto) {
            $puntos[] = ['clave' => (string) $clave, 'numero' => count($puntos) + 1, 'texto' => $texto];
        }

        return $puntos;
    }

    /**
     * Como se identifica un punto: por su texto.
     *
     * El nombre es la clave a proposito, y no el `item_id`: es lo unico que
     * comparten una respuesta nueva y una migrada, y ademas es lo que se lee.
     * Cuando el texto falta —el migrador no encontro el punto en `ihm_items`—
     * se cae a la posicion dentro de la fila, que al menos mantiene alineada la
     * columna y no mezcla dos puntos distintos en una.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function identidad(mixed $item, int $posicion): array
    {
        $nombre = is_array($item) ? trim((string) ($item['item'] ?? $item['name'] ?? '')) : '';

        return $nombre !== '' ? [$nombre, $nombre] : ['#' . $posicion, null];
    }

    // ── Cada herramienta ────────────────────────────────────────────────────

    /**
     * Una herramienta inspeccionada: su identidad, la fila de la matriz y, si
     * hay algo que corregir, la linea de correccion que va debajo.
     */
    private static function herramienta(array $fila, int $numero, array $puntos, array $config): array
    {
        $respuestas = [];
        $malas = 0;
        $sinResponder = 0;

        foreach (self::items($fila) as $posicion => $item) {
            [$clave] = self::identidad($item, $posicion);
            $etiqueta = self::texto(is_array($item) ? ($item['answer'] ?? null) : null);

            if ($etiqueta === null) {
                $respuestas[$clave] = ['estado' => 'vacio', 'texto' => null];
                $sinResponder++;
                continue;
            }

            // La regla de que respuesta es mala vive en FormFindingsService y se
            // usa esa, no una copia: si el PDF dijera «conforme» donde el
            // contador de no conformidades cuenta una, el papel y la ficha del
            // plan contarian cosas distintas del mismo dia de trabajo.
            $tono = app(FormFindingsService::class)->tono($etiqueta, $config);

            if ($tono === FormFindingsService::MALA) {
                $malas++;
            }

            $respuestas[$clave] = ['estado' => $tono, 'texto' => $etiqueta];
        }

        $estado = self::estado($malas, $sinResponder, count(self::items($fila)));

        return [
            'numero' => $numero,
            'nombre' => self::texto($fila['tool'] ?? $fila['name'] ?? $fila['herramienta'] ?? null),
            // El nombre de la herramienta se escribe a mano (en la v1 es un
            // texto con autocompletado), asi que estas dos casi nunca vienen.
            // Se pintan si vienen y no se inventa una columna vacia si no.
            'codigo'   => self::texto($fila['code'] ?? $fila['codigo'] ?? $fila['tool_code'] ?? null),
            'cantidad' => self::texto($fila['quantity'] ?? $fila['cantidad'] ?? null),
            // Alineadas con las columnas: la herramienta que no tenia ese punto
            // en su lista deja el hueco «ausente», que no es lo mismo que no
            // haberlo respondido.
            'celdas' => array_map(
                fn (array $punto) => $respuestas[$punto['clave']] ?? ['estado' => 'ausente', 'texto' => null],
                $puntos,
            ),
            'estado'        => $estado,
            'sin_responder' => $sinResponder,
            'correcciones'  => self::correcciones($fila, $config, $estado === 'bad'),
        ];
    }

    /**
     * En que quedo la herramienta.
     *
     * Una respuesta mala manda sobre todo lo demas, y ahi este PDF se separa a
     * proposito de la pantalla: `estadoChecklist()` de `respuestas.js` avisa
     * primero de lo que falta, porque esta guiando a quien rellena. El papel no
     * guia a nadie —es el registro de lo que se encontro— y una herramienta con
     * el mango rajado tiene que leerse «No conforme» aunque el formato quedara a
     * medias.
     *
     * Sin una sola respuesta no se dice «Conforme»: una herramienta sin
     * inspeccionar no es una herramienta buena.
     */
    private static function estado(int $malas, int $sinResponder, int $total): string
    {
        if ($malas > 0) {
            return 'bad';
        }

        return ($sinResponder > 0 || $total === 0) ? 'warn' : 'ok';
    }

    /**
     * La linea de correccion de la herramienta: medida, fecha, responsable y
     * verificacion, cada una con su rotulo.
     *
     * Va debajo de la fila y no en columnas al final, como en la v1, por sitio:
     * la matriz ya se lleva el ancho de la hoja y tres columnas de texto libre
     * en A4 vertical salen en una tira de dos letras por linea. Debajo se lee, y
     * ademas queda pegada a la herramienta a la que corrige.
     *
     * @return array<int, array{clave: string, etiqueta: string, valor: ?string}>
     */
    private static function correcciones(array $fila, array $config, bool $noConforme): array
    {
        $valores = [];

        // Lo que la plantilla declara existe aunque este vacio: es una columna
        // del formato, no un dato suelto que aparecio en una fila.
        foreach (($config['extra'] ?? []) as $clave) {
            if (is_string($clave) && $clave !== '') {
                $valores[self::canonica($clave)] = null;
            }
        }

        foreach ($fila as $clave => $valor) {
            if (! is_string($clave) || self::esEstructura($clave) || self::esIdentificador($clave)) {
                continue;
            }

            $canonica = self::canonica($clave);
            $texto = self::texto($valor, $canonica);

            // Una clave vacia que la plantilla no declara no aporta nada: es
            // ruido de una version anterior del formato.
            if ($texto === null && ! array_key_exists($canonica, $valores)) {
                continue;
            }

            $valores[$canonica] = $texto ?? ($valores[$canonica] ?? null);
        }

        if ($noConforme) {
            // El hueco de la verificacion se imprime aunque nadie lo declare:
            // ver la nota de la constante.
            $valores += [self::VERIFICACION => null];
        } else {
            // Herramienta conforme: lo que este en blanco no se imprime. Un
            // rotulo con una raya al lado solo dice algo cuando habia algo que
            // corregir; si no, es una fila de ruido debajo de cada herramienta.
            $valores = array_filter($valores, fn (?string $valor) => $valor !== null);
        }

        $ordenadas = [];

        foreach (self::ORDEN as $clave) {
            if (array_key_exists($clave, $valores)) {
                $ordenadas[] = self::correccion($clave, $valores[$clave]);
                unset($valores[$clave]);
            }
        }

        foreach ($valores as $clave => $valor) {
            $ordenadas[] = self::correccion((string) $clave, $valor);
        }

        return $ordenadas;
    }

    /**
     * El rotulo de una correccion.
     *
     * Se resuelve aqui y no en el parcial porque la clave es libre: el formato
     * lo configura quien lo crea y puede declarar un `extra` que nadie tradujo.
     * En ese caso se imprime la clave hecha legible —feo pero honesto— en vez de
     * la cadena `form_submissions.pdf.tool_checklist.loquesea` en mitad de un
     * documento firmado. Los rotulos van al lado de los demas textos de este
     * campo y no en un `extra.` aparte, igual que en el EPP.
     *
     * @return array{clave: string, etiqueta: string, valor: ?string}
     */
    private static function correccion(string $clave, ?string $valor): array
    {
        $ruta = 'form_submissions.pdf.tool_checklist.' . $clave;
        $rotulo = __($ruta);

        return [
            'clave'    => $clave,
            'etiqueta' => is_string($rotulo) && $rotulo !== $ruta
                ? $rotulo
                : ucfirst(str_replace('_', ' ', $clave)),
            'valor'    => $valor,
        ];
    }

    // ── Apoyo ───────────────────────────────────────────────────────────────

    /**
     * Las herramientas que hay en una respuesta. Normalmente una.
     *
     * `validarValor()` solo exige una lista no vacia, asi que lo guardado puede
     * tener mas de una forma. Se aceptan las tres que existen —igual que en
     * `FormSubmissionPdfService::comoFilas()`, y por la misma razon: un formato
     * lo configura quien quiere y en el papel no puede faltar una herramienta
     * por como quedo escrita en la base de datos.
     *
     * @return array<int, array>
     */
    private static function comoFilas(FormAnswer $respuesta): array
    {
        $valor = $respuesta->value_json;

        if (is_array($valor) && ! array_is_list($valor)) {
            return [$valor];
        }

        if (is_array($valor) && $valor !== []) {
            // Una lista de herramientas enteras en una sola respuesta.
            if (self::sonHerramientas($valor)) {
                return array_values($valor);
            }

            // Si no, es la lista de puntos de una herramienta a la que le falta
            // la cabecera: se pinta sin nombre antes que tirar las respuestas.
            return [['items' => $valor]];
        }

        return filled($respuesta->value_text) ? [['tool' => (string) $respuesta->value_text]] : [];
    }

    /** Una lista de herramientas, y no de puntos: cada elemento trae cabecera. */
    private static function sonHerramientas(array $valor): bool
    {
        foreach ($valor as $elemento) {
            if (! is_array($elemento)
                || array_is_list($elemento)
                || ! (array_key_exists('tool', $elemento) || array_key_exists('items', $elemento))) {
                return false;
            }
        }

        return true;
    }

    /** Los puntos de la fila, siempre como lista indexada por posicion. */
    private static function items(array $fila): array
    {
        $items = $fila['items'] ?? $fila['puntos'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    private static function canonica(string $clave): string
    {
        return self::ALIAS[$clave] ?? $clave;
    }

    private static function esEstructura(string $clave): bool
    {
        return in_array($clave, self::ESTRUCTURA, true);
    }

    /**
     * Todo lo que solo sirve dentro de la base de datos.
     *
     * `sinIdentificadores()` del servicio generico se queda en los `_slug`
     * porque es lo que veia venir del EPP. El IHM migrado trae ademas
     * `item_id`, y las filas del EPP `person_id` y `legacy_plan_worker_id`.
     */
    private static function esIdentificador(string $clave): bool
    {
        return $clave === 'id'
            || str_ends_with($clave, '_id')
            || str_ends_with($clave, '_slug')
            || str_starts_with($clave, 'legacy_');
    }

    /**
     * Cualquier valor guardado, dicho en una linea, o null si no dice nada.
     *
     * La fecha limite se formatea SIN pasarla por la zona horaria a proposito:
     * es un dia del calendario —«para el 8 de agosto»—, no un instante, y
     * convertir el 2026-08-08 guardado a la hora de Lima lo dejaria en el 7.
     */
    private static function texto(mixed $valor, ?string $clave = null): ?string
    {
        if ($valor === null || $valor === '' || (is_array($valor) && $valor === [])) {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? __('global.yes') : __('global.no');
        }

        if (is_array($valor)) {
            $partes = array_filter(array_map(
                fn ($item) => is_scalar($item) ? trim((string) $item) : null,
                $valor,
            ));

            return $partes === [] ? null : implode(' · ', $partes);
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        if ($clave === 'deadline_date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
            return Carbon::parse($texto)->format(Tz::DATE_FORMAT);
        }

        return $texto;
    }
}
