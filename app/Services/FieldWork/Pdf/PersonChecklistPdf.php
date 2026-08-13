<?php

namespace App\Services\FieldWork\Pdf;

use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\Person;
use App\Services\FieldWork\FormFindingsService;
use Illuminate\Support\Collection;

/**
 * El EPP por trabajador, resuelto para el papel.
 *
 * El PDF pintaba este campo con el serializador generico de
 * `FormSubmissionPdfService::tabla()`, que deriva las columnas de las claves
 * del JSON guardado. Para el EPP eso salia asi: «Person id», «Legacy plan
 * worker id», «Correction measure», «Deadline date», «Correction verification»
 * y una ultima columna con los veinticinco items concatenados con comas. Es
 * decir: el documento que la empresa conserva y que puede acabar delante de un
 * inspector enseñaba nombres de columna de la base de datos, en ingles, sin el
 * nombre del trabajador por ningun lado —porque las entregas migradas guardan
 * `person_id` y no `person_name`— y con las respuestas en un parrafo.
 *
 * Dos cosas que esta clase hace y el generico no puede hacer:
 *
 *  1. **Lista blanca de claves.** `sinIdentificadores()` tapa lo que acaba en
 *     `_slug`, y por eso dejo pasar `person_id` y `legacy_plan_worker_id`. Aqui
 *     no se filtra lo malo: se lee SOLO lo que se imprime. Una clave nueva en
 *     el JSON —la que sea— no puede volver a aparecer sola en el papel.
 *  2. **Nombre del trabajador siempre.** Las 14 435 entregas migradas
 *     (`LegacyFormMapper::checklistDePersona`) solo traen `person_id`; las
 *     nuevas traen `person_name` porque lo escribe la pantalla. Un EPP sin
 *     nombres no vale como documento, asi que cuando falta se resuelve contra
 *     `people`, en una sola consulta para todas las filas.
 *
 * Las respuestas se guardan como etiqueta («Conforme») y nunca como numero,
 * asi que aqui se traducen por su TEXTO —no por su posicion en el catalogo—
 * con la misma regla que usa `FormFindingsService::tono()` para contar las no
 * conformidades. Se reutiliza esa funcion a proposito: si el papel pintase de
 * rojo con un criterio y el contador del plan contase con otro, tendriamos el
 * mismo documento diciendo dos cosas (ya paso con el 0 y el 2 de la v1).
 */
final class PersonChecklistPdf
{
    /** Respuesta que no se dio. No es «no aplica»: ver `tonoDe()`. */
    public const SIN_RESPONDER = 'sin';

    /**
     * Las tres columnas del final del papel de la v1
     * (`f3_document_workers`), por si la plantilla no declara `extra`.
     */
    protected const EXTRA_POR_DEFECTO = ['correction_measure', 'deadline_date', 'correction_verification'];

    /**
     * Lo que el parcial necesita, ya resuelto.
     *
     * `items` va numerado porque en el papel son columnas: el rotulo entero no
     * cabe en una columna de veinte puntos y se explica arriba, en su leyenda,
     * igual que en la inspeccion de herramientas.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array{
     *     items: array<int, array{numero: int, texto: string}>,
     *     trabajadores: array<int, array<string, mixed>>,
     *     no_conformes: int,
     *     leyenda: array<int, array{simbolo: string, texto: string, tono: string}>,
     * }
     */
    public static function datos(FormField $campo, Collection $respuestas): array
    {
        $config = $campo->config ?? [];
        $filas  = self::filas($respuestas);
        // En el orden de los grupos, que es el que hace que cada rotulo de la
        // cabecera cubra columnas seguidas. Sin grupos es el del catalogo.
        $items  = self::ordenados($config, self::items($config, $filas));
        $nombres = self::nombresPorPersona($filas);
        $extra  = self::clavesExtra($config);

        $trabajadores = [];
        $noConformes = 0;

        foreach ($filas as $i => $fila) {
            $trabajador = self::trabajador($fila, $i + 1, $items, $extra, $nombres);

            $noConformes += $trabajador['no_conformes'];
            $trabajadores[] = $trabajador;
        }

        $numerados = array_map(
            fn (int $i, string $texto) => ['numero' => $i + 1, 'texto' => $texto],
            array_keys($items),
            $items,
        );

        return [
            'items'        => $numerados,
            // Y cuantas columnas cubre cada rotulo de grupo, para la fila que
            // va encima de la cuadricula. Vacio = sin grupos, y la cabecera se
            // queda como en los demas checklists.
            'grupos'       => self::grupos($config, $items),
            'trabajadores' => $trabajadores,
            'no_conformes' => $noConformes,
            // Que significa cada marca, con las palabras de esta plantilla.
            'leyenda' => Simbolos::leyenda($config['answers'] ?? []),
        ];
    }

    // ── Los grupos (cabeza, cara, manos…) ───────────────────────────────────

    /**
     * Los items repartidos en sus grupos, en el orden de los grupos.
     *
     * El EPP del sistema anterior agrupaba los equipos por parte del cuerpo
     * (`epp_categories`) y es la unica de las cuatro listas que lo hace. En una
     * cuadricula de veinticinco columnas el rotulo de grupo es lo que convierte
     * una fila de numeros en algo que se recorre: se busca «manos» y se miran
     * esas cinco, en vez de leer las veinticinco.
     *
     * LA AGRUPACION ES UNA VISTA SOBRE `items`, NO OTRA LISTA. `config.items`
     * sigue siendo el catalogo —es lo que se guarda en cada respuesta y contra
     * lo que casa la migracion— y `config.groups` solo dice bajo que rotulo va
     * cada uno. Un item que alguien añada al catalogo y olvide meter en un
     * grupo sale igual, al final y sin rotulo, en vez de desaparecer.
     *
     * Es la misma regla que aplica la pantalla de llenado (`agrupar()` en
     * `respuestas.js`): el papel y la tablet reparten igual o no se pueden
     * cotejar.
     *
     * @param  array<int, string>  $items  el catalogo, en su orden
     * @return array<int, array{nombre: ?string, items: array<int, string>}>
     */
    protected static function agrupar(array $config, array $items): array
    {
        $declarados = is_array($config['groups'] ?? null) ? $config['groups'] : [];

        if ($declarados === []) {
            return $items === [] ? [] : [['nombre' => null, 'items' => $items]];
        }

        $disponibles = array_fill_keys($items, true);
        $grupos = [];

        foreach ($declarados as $grupo) {
            $suyos = [];

            foreach ((array) ($grupo['items'] ?? []) as $item) {
                $item = (string) $item;

                // Un item declarado en dos grupos se queda en el primero:
                // repetirlo pediria dos respuestas para la misma casilla.
                if (isset($disponibles[$item])) {
                    $suyos[] = $item;
                    unset($disponibles[$item]);
                }
            }

            if ($suyos !== []) {
                $nombre = trim((string) ($grupo['name'] ?? ''));
                $grupos[] = ['nombre' => $nombre !== '' ? $nombre : null, 'items' => $suyos];
            }
        }

        $sueltos = array_values(array_filter($items, fn (string $i) => isset($disponibles[$i])));

        if ($sueltos !== []) {
            $grupos[] = ['nombre' => null, 'items' => $sueltos];
        }

        return $grupos;
    }

    /**
     * El catalogo reordenado por grupos: cada rotulo cubre columnas seguidas.
     *
     * @param  array<int, string>  $items
     * @return array<int, string>
     */
    protected static function ordenados(array $config, array $items): array
    {
        return array_merge(...array_column(self::agrupar($config, $items), 'items')) ?: [];
    }

    /**
     * Cuantas columnas cubre cada rotulo, para el `colspan` de la cabecera.
     *
     * Devuelve vacio cuando no hay ni un rotulo —el caso de los demas
     * checklists— y asi la plantilla no pinta una fila de grupos en blanco.
     *
     * @param  array<int, string>  $items
     * @return array<int, array{nombre: ?string, columnas: int}>
     */
    protected static function grupos(array $config, array $items): array
    {
        $grupos = self::agrupar($config, $items);

        $hayRotulos = array_filter($grupos, fn (array $g) => $g['nombre'] !== null) !== [];

        if (! $hayRotulos) {
            return [];
        }

        return array_map(
            fn (array $g) => ['nombre' => $g['nombre'], 'columnas' => count($g['items'])],
            $grupos,
        );
    }

    // ── Las filas guardadas ─────────────────────────────────────────────────

    /**
     * Una fila por trabajador, en el orden en que se llenaron.
     *
     * Lo normal es una `form_answers` por trabajador con su `row_index` (asi lo
     * manda `FormFill.vue` y asi lo escribio la migracion). Se admite igual que
     * una sola respuesta traiga la lista entera: es como quedaron algunas
     * entregas antiguas y perder a media cuadrilla por eso seria peor que
     * aceptar las dos formas.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array<int, array<string, mixed>>
     */
    protected static function filas(Collection $respuestas): array
    {
        $filas = [];

        foreach ($respuestas->sortBy('row_index') as $respuesta) {
            $valor = $respuesta instanceof FormAnswer ? $respuesta->value_json : null;

            if (! is_array($valor) || $valor === []) {
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
     * Los items del catalogo, en su orden, mas los que solo aparezcan en las
     * respuestas.
     *
     * El orden es el de la plantilla porque es el del papel y el de la pantalla:
     * casco, barbiquejo, caretas… hasta las botas. Quien compara el PDF con la
     * inspeccion busca por ese orden.
     *
     * Los que sobran se añaden al final en vez de descartarse: si alguien quito
     * un item de la plantilla despues de que se firmara una entrega, esa
     * respuesta sigue siendo parte del documento firmado y no puede desaparecer
     * del PDF (se pinta la version congelada, pero un catalogo editado a mano
     * dentro de la misma version se cuela igual).
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, string>
     */
    protected static function items(array $config, array $filas): array
    {
        $items = [];

        foreach (($config['items'] ?? []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[trim($item)] = true;
            }
        }

        foreach ($filas as $fila) {
            foreach (self::itemsDeLaFila($fila) as $nombre => $respuesta) {
                $items[$nombre] = true;
            }
        }

        return array_keys($items);
    }

    /**
     * Las respuestas de una fila indexadas por nombre de item.
     *
     * Se indexa por NOMBRE y no por `item_id` porque el id es de la base vieja
     * y aqui no se imprime: el catalogo de la plantilla son textos.
     *
     * @return array<string, string|null>
     */
    protected static function itemsDeLaFila(array $fila): array
    {
        $salida = [];

        foreach (($fila['items'] ?? []) as $x) {
            if (! is_array($x)) {
                continue;
            }

            $nombre = trim((string) ($x['item'] ?? ''));

            if ($nombre !== '') {
                $salida[$nombre] = self::comoTexto($x['answer'] ?? null);
            }
        }

        return $salida;
    }

    /**
     * Las respuestas de items que se quedaron sin nombre.
     *
     * Pasa en lo migrado: `checklistDePersona()` resuelve el nombre contra el
     * catalogo por `epp_item_id` y escribe null si aquel item ya no existia.
     * Con nombre no se puede pintar, pero **una respuesta negativa no se tira**:
     * un «No conforme» escondido es justo lo contrario de para lo que sirve este
     * documento. Sale al final del bloque, rotulado como item sin identificar.
     *
     * @return array<int, string|null>
     */
    protected static function huerfanas(array $fila): array
    {
        $salida = [];

        foreach (($fila['items'] ?? []) as $x) {
            if (! is_array($x) || trim((string) ($x['item'] ?? '')) !== '') {
                continue;
            }

            $respuesta = self::comoTexto($x['answer'] ?? null);

            if ($respuesta !== null) {
                $salida[] = $respuesta;
            }
        }

        return $salida;
    }

    // ── Un trabajador ───────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $items
     * @param  array<int, string>  $extra
     * @param  array<int, string>  $nombres  nombres resueltos por `person_id`
     */
    protected static function trabajador(array $fila, int $numero, array $items, array $extra, array $nombres): array
    {
        $suyas = self::itemsDeLaFila($fila);
        $celdas = [];
        $noConformes = 0;
        $sinResponder = 0;

        // Una celda por columna, en el orden de las columnas. Alineadas a la
        // fuerza: la fila que no traiga un item deja su hueco, que es lo que
        // hace legible una cuadricula de veinticinco columnas.
        foreach ($items as $item) {
            $respuesta = $suyas[$item] ?? null;
            $tono = self::tonoDe($respuesta);

            $noConformes += $tono === FormFindingsService::MALA ? 1 : 0;
            $sinResponder += $tono === self::SIN_RESPONDER ? 1 : 0;

            $celdas[] = ['tono' => $tono, 'respuesta' => self::etiqueta($respuesta)];
        }

        // Las respuestas cuyo item se quedo sin nombre no tienen columna donde
        // ir —no se sabe cual es— pero tampoco se tiran: van escritas debajo
        // del trabajador, con la linea de correccion. Un «No conforme»
        // escondido es lo contrario de para lo que sirve este documento.
        $huerfanas = [];

        foreach (self::huerfanas($fila) as $respuesta) {
            $tono = self::tonoDe($respuesta);
            $noConformes += $tono === FormFindingsService::MALA ? 1 : 0;

            $huerfanas[] = ['respuesta' => self::etiqueta($respuesta), 'tono' => $tono];
        }

        return [
            'numero'    => $numero,
            'nombre'    => self::nombre($fila, $numero, $nombres),
            // El documento tal como se guardo al llenar: ya viene enmascarado
            // (`safe_num_doc`) si quien llenaba no tenia permiso para verlo. No
            // se va a buscar el entero a `people`: el PDF se manda por correo y
            // no puede enseñar mas de lo que enseñaba la pantalla.
            'documento' => self::comoTexto($fila['person_doc'] ?? null),
            'celdas'    => $celdas,
            'huerfanas' => $huerfanas,
            'no_conformes'  => $noConformes,
            'sin_responder' => $sinResponder,
            // En que quedo el trabajador, con la misma regla que la inspeccion
            // de herramientas: una sola respuesta mala manda sobre todo lo
            // demas, y si no hay ninguna pero quedan huecos, esta sin terminar.
            'estado'        => $noConformes > 0 ? 'bad' : ($sinResponder > 0 ? 'warn' : 'ok'),
            'correccion'    => self::correccion($fila, $extra),
        ];
    }

    /**
     * El nombre que se imprime.
     *
     * Apellido primero cuando hay que resolverlo, que es como lista a la gente
     * la pantalla de llenado (`list_name`) y como lo hacia el sistema anterior
     * (`Worker#str_complete_name_pro`). Si no hay forma de saberlo se numera la
     * fila: «Trabajador 3» dice menos que un nombre, pero mantiene la cuenta de
     * la cuadrilla, y una fila sin rotulo en una inspeccion de EPP no se puede
     * cotejar con nada.
     *
     * @param  array<int, string>  $nombres
     */
    protected static function nombre(array $fila, int $numero, array $nombres): string
    {
        $guardado = self::comoTexto($fila['person_name'] ?? null);

        if ($guardado !== null) {
            return $guardado;
        }

        $id = $fila['person_id'] ?? null;

        return (is_int($id) || is_string($id)) && isset($nombres[(int) $id])
            ? $nombres[(int) $id]
            : self::traducir('unnamed_worker', ['number' => $numero], 'Trabajador ' . $numero);
    }

    /**
     * Los nombres que faltan, de una sola consulta.
     *
     * `withTrashed()` porque un EPP de hace tres años puede tener a alguien que
     * ya no esta en la empresa: el documento firmado no cambia porque su ficha
     * se haya dado de baja.
     *
     * @param  array<int, array<string, mixed>>  $filas
     * @return array<int, string>
     */
    protected static function nombresPorPersona(array $filas): array
    {
        $ids = [];

        foreach ($filas as $fila) {
            if (self::comoTexto($fila['person_name'] ?? null) !== null) {
                continue;
            }

            $id = $fila['person_id'] ?? null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        return Person::withTrashed()
            ->whereIn('id', array_keys($ids))
            ->get(['id', 'name', 'lastname'])
            ->mapWithKeys(fn (Person $p) => [$p->id => $p->list_name])
            ->all();
    }

    /**
     * Medida correctiva, fecha limite y verificacion: solo lo que traiga.
     *
     * Son las tres ultimas columnas del papel y en la v1 salian siempre, vacias
     * en casi todas las filas —solo se llenan cuando algo sale no conforme—.
     * Aqui se imprimen debajo del trabajador al que pertenecen y solo si dicen
     * algo, que es lo que las hace visibles cuando de verdad las hay.
     *
     * Las claves salen de `config.extra` para que una plantilla que añada una
     * cuarta columna desde la interfaz la pinte sin tocar esto.
     *
     * @param  array<int, string>  $extra
     * @return array<int, array{etiqueta: string, valor: string}>
     */
    protected static function correccion(array $fila, array $extra): array
    {
        $salida = [];

        foreach ($extra as $clave) {
            $valor = self::comoTexto($fila[$clave] ?? null);

            if ($valor === null) {
                continue;
            }

            $salida[] = [
                'etiqueta' => self::rotulo($clave),
                'valor'    => str_ends_with($clave, '_date') ? self::fecha($valor) : $valor,
            ];
        }

        return $salida;
    }

    /** @return array<int, string> */
    protected static function clavesExtra(array $config): array
    {
        $extra = array_values(array_filter(
            $config['extra'] ?? [],
            fn ($clave) => is_string($clave) && trim($clave) !== '',
        ));

        return $extra === [] ? self::EXTRA_POR_DEFECTO : $extra;
    }

    // ── Textos ──────────────────────────────────────────────────────────────

    /**
     * De que clase es la respuesta: buena, no aplica, mala o sin dar.
     *
     * El tono lo decide `FormFindingsService::tono()`, que es lo que cuenta las
     * no conformidades del plan. Lo unico que se añade aqui es distinguir el
     * hueco: para contar da igual —ni «no aplica» ni el hueco suman— pero en el
     * papel no es lo mismo. «No aplica» es una decision del inspector (ese
     * trabajador no necesitaba careta de soldar); el hueco es un item que nadie
     * miro, y quien lea el documento tiene derecho a notar la diferencia.
     */
    protected static function tonoDe(?string $respuesta): string
    {
        if ($respuesta === null) {
            return self::SIN_RESPONDER;
        }

        return app(FormFindingsService::class)->tono($respuesta);
    }

    /**
     * La etiqueta legible de una respuesta, en el idioma del documento.
     *
     * Se traduce por el TEXTO guardado y no por su posicion en `config.answers`:
     * el catalogo del IHM ni siquiera empieza por la respuesta buena, asi que la
     * posicion no significa nada (ver `LegacyFormMapper::RESPUESTAS_EPP`).
     *
     * Lo que no reconoce se imprime tal cual. Un cliente puede haber cambiado
     * las opciones desde la interfaz —«Correcto», «Defectuoso»— y traducir eso a
     * «Conforme» seria cambiar lo que se firmo; peor que dejarlo en su idioma.
     */
    protected static function etiqueta(?string $respuesta): ?string
    {
        if ($respuesta === null) {
            return null;
        }

        $clave = match (mb_strtolower(trim($respuesta))) {
            'conforme', 'cumple'       => 'compliant',
            'no conforme', 'no cumple' => 'non_compliant',
            'no aplica', 'n/a', 'na'   => 'not_applicable',
            default                    => null,
        };

        return $clave === null
            ? $respuesta
            : self::traducir('answers.' . $clave, [], $respuesta);
    }

    /**
     * Traduce, y si la clave no existe deja el texto de reserva.
     *
     * Misma precaucion que `FormSubmissionPdfService::traducir()`: lo que no
     * puede pasar nunca es que el documento imprima la ruta de una clave. Un
     * idioma al que le falte una linea saca el texto guardado o el castellano,
     * que se entiende; «form_submissions.pdf.person_checklist.answers.compliant»
     * no se entiende y ademas queda por escrito.
     */
    protected static function traducir(string $sufijo, array $reemplazos, string $porDefecto): string
    {
        $ruta = 'form_submissions.pdf.person_checklist.' . $sufijo;
        $texto = __($ruta, $reemplazos);

        return is_string($texto) && $texto !== $ruta ? $texto : $porDefecto;
    }

    /**
     * El rotulo de una columna de correccion, traducido si lo conocemos.
     *
     * Si no lo conocemos se hace legible el codigo —«Responsible» →
     * «Responsible»— pero NUNCA se imprime la ruta de la clave: un
     * «form_submissions.pdf.person_checklist.responsible» en mitad de un
     * documento de seguridad es exactamente el problema que se esta arreglando.
     */
    protected static function rotulo(string $clave): string
    {
        return self::traducir($clave, [], ucfirst(str_replace('_', ' ', $clave)));
    }

    /**
     * Una fecha limite, en el formato del proyecto (`d-m-Y`).
     *
     * A mano y no con `Tz::formatDate()` a proposito: una fecha limite no tiene
     * hora. Pasarla por el conversor de zona la interpreta como medianoche UTC y
     * en America/Lima la devuelve **un dia antes** — el 12 de mayo impreso como
     * 11 de mayo en el documento donde consta el plazo para reponer un arnes.
     */
    protected static function fecha(string $valor): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $partes)) {
            return $valor;
        }

        return $partes[3] . '-' . $partes[2] . '-' . $partes[1];
    }

    /** Un valor guardado a texto imprimible, o null si no dice nada. */
    protected static function comoTexto(mixed $valor): ?string
    {
        if ($valor === null || is_array($valor) || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
