<?php

namespace App\Services\FieldWork\Pdf;

use App\Models\FormAnswer;
use App\Models\FormField;
use App\Services\FieldWork\FormFindingsService;
use App\Support\Catalogo;
use Illuminate\Support\Collection;

/**
 * El banco de preguntas del PTF —«Pare, Tome 5»— resuelto para el papel.
 *
 * QUE ESTABA MAL. Este campo salia por el serializador generico de
 * `FormSubmissionPdfService::tabla()`, que deriva las columnas de las claves
 * del JSON guardado. Para el banco de preguntas eso imprime literalmente
 * «Pregunta id | Question | Answer»: dos cabeceras en INGLES dentro de un
 * documento en castellano —son los nombres de las claves que fija
 * `LegacyFormMapper::CLAVES_DE_PREGUNTA`, no palabras que nadie escribiera para
 * ser leidas— y una tercera columna con el `ptf_question_id` de la base vieja,
 * un numero que no significa nada fuera del sistema y que ademas enseña como
 * esta hecho por dentro. `sinIdentificadores()` no lo quita porque solo mira
 * las claves que acaban en `_slug`. Lo prueba hoy
 * `FormSubmissionPdfTest`: `assertSame(['Question', 'Answer'], $cabeceras)`.
 *
 * Y faltaba lo unico que de verdad hay que leer: **en el PTF una respuesta «No»
 * es una observacion**. Alguien contesto que no a una pregunta de seguridad
 * antes de empezar el trabajo. El volcado generico lo dejaba como una fila mas
 * entre diecisiete, en gris, indistinguible de un «Si».
 *
 * QUE HACE ESTA CLASE, y por eso existe aparte del serializador:
 *
 *  1. El orden y el contenido salen del CATALOGO de la plantilla congelada
 *     (`config.questions`), no de lo guardado. Las diecisiete preguntas son el
 *     cuestionario oficial y se imprimen en su orden aunque falte contestar
 *     alguna, igual que en el papel de la v1, donde las diecisiete filas estan
 *     siempre y lo que cambia es donde esta la equis. Un PTF entregado a medias
 *     tiene que enseñar QUE quedo sin contestar, no una lista mas corta.
 *  2. Cuenta las que valen como observacion, y con la MISMA regla que el numero
 *     de la ficha del plan: `FormFindingsService::tono()`. Si aqui se
 *     reescribiera la regla, el papel podria decir dos observaciones y la ficha
 *     tres, y el que firma no tiene forma de saber cual de las dos miente.
 *  3. No lee ninguna clave de identificador. Solo el enunciado y la respuesta,
 *     que es lo unico que se lee en voz alta en la charla de cinco minutos.
 *
 * El texto visible NO sale de aqui: esta clase devuelve datos y el parcial
 * `pdf/campos/question_bank.blade.php` los traduce con
 * `form_submissions.pdf.question_bank.*`. Es lo que hace el resto del PDF y
 * permite probar esta salida sin montar el contenedor de traducciones.
 *
 * Lo que NO se puede reproducir de la v1: alli las preguntas salen en cinco
 * bloques titulados («¡DETENTE y piensa antes de actuar!»…) con su icono en una
 * celda con `rowspan`. El motor nuevo guarda `config.questions` como lista
 * plana, asi que la agrupacion no existe en el dato (docs/FORMATOS.md §5.2). No
 * se inventa aqui: agrupar por posicion —cuatro, tres, tres, seis, una— seria
 * cablear el PTF peruano dentro de un motor que deja definir el formato desde
 * la pantalla, y el primer cliente que añada una pregunta veria los titulos
 * corridos de sitio.
 */
final class QuestionBankPdf
{
    /**
     * Donde puede venir el enunciado y la respuesta de cada par.
     *
     * Las claves buenas son `question` y `answer` (las de
     * `LegacyFormMapper::CLAVES_DE_PREGUNTA`, que son las que lee
     * `QuestionBankField.vue`). Se aceptan tambien las castellanas porque
     * durante un tiempo se guardo asi: la migracion
     * `2026_08_08_175000_fix_composite_answer_keys` las renombro en sitio, y
     * una entrega que no pasara por ella —una copia de base restaurada de
     * antes— se imprimiria en blanco sin decir por que.
     */
    protected const CLAVES_PREGUNTA = ['question', 'pregunta'];

    protected const CLAVES_RESPUESTA = ['answer', 'respuesta'];

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array{
     *     preguntas: array<int, array{numero: int, texto: ?string, respuesta: ?string,
     *                                 tono: ?string, observacion: bool, fuera_de_catalogo: bool}>,
     *     total: int, respondidas: int, sin_responder: int,
     *     observaciones: int, observadas: array<int, int>, hay_fuera_de_catalogo: bool
     * }
     */
    public static function datos(FormField $campo, Collection $respuestas): array
    {
        $config    = $campo->config ?? [];
        $catalogo  = self::catalogo($config, 'questions');
        $posibles  = self::catalogo($config, 'answers');
        $pares     = self::pares($respuestas);
        $preguntas = [];

        // Indice enunciado → primer par que lo contesta. El PRIMERO y no el
        // ultimo, porque es lo que hace la pantalla de llenado al recomponer la
        // lista contra el catalogo (`guardadas.find(...)` en
        // QuestionBankField.vue). El papel y la pantalla no pueden enseñar
        // respuestas distintas de la misma pregunta.
        $porEnunciado = [];

        foreach ($pares as $indice => $par) {
            if ($par['texto'] !== null && ! isset($porEnunciado[$par['texto']])) {
                $porEnunciado[$par['texto']] = $indice;
            }
        }

        $delCatalogo = [];

        foreach ($catalogo as $texto) {
            $delCatalogo[$texto] = true;
            $indice = $porEnunciado[$texto] ?? null;

            $preguntas[] = self::pregunta(
                $texto,
                $indice === null ? null : $pares[$indice]['respuesta'],
                $posibles,
                false,
                $config,
            );
        }

        // Lo contestado que el catalogo ya no tiene va detras, nunca fuera.
        //
        // Pasa con lo migrado: `bancoDePreguntas()` escribe el enunciado que
        // tenia la `ptf_question` en la base vieja, y una pregunta que alli se
        // borro o se reescribio deja de cuadrar con la lista sembrada. Tirar
        // esa fila seria borrar del documento una respuesta que alguien dio.
        foreach ($pares as $par) {
            if ($par['texto'] !== null && isset($delCatalogo[$par['texto']])) {
                continue;
            }

            // Un par sin enunciado y sin respuesta no es nada que contar.
            if ($par['texto'] === null && $par['respuesta'] === null) {
                continue;
            }

            $preguntas[] = self::pregunta($par['texto'], $par['respuesta'], $posibles, true, $config);
        }

        $observadas = [];

        foreach ($preguntas as $orden => $pregunta) {
            $preguntas[$orden]['numero'] = $orden + 1;

            if ($pregunta['observacion']) {
                $observadas[] = $orden + 1;
            }
        }

        $respondidas = count(array_filter($preguntas, fn (array $p) => $p['respuesta'] !== null));

        return [
            'preguntas'     => $preguntas,
            'grupos'        => self::grupos($config, $preguntas),
            'total'         => count($preguntas),
            'respondidas'   => $respondidas,
            'sin_responder' => count($preguntas) - $respondidas,
            'observaciones' => count($observadas),
            // Los numeros de fila, para que el resumen de arriba señale donde
            // mirar sin repetir los enunciados enteros: son frases de dos
            // lineas y repetirlas convierte el resumen en otra tabla.
            'observadas'    => $observadas,
            'hay_fuera_de_catalogo' => array_filter($preguntas, fn (array $p) => $p['fuera_de_catalogo']) !== [],
        ];
    }

    /**
     * Las preguntas repartidas en sus bloques, como el papel de la v1: «1.
     * ¡DETENTE y piensa antes de actuar!» con su icono al lado, y sus preguntas
     * debajo.
     *
     * ES UNA VISTA SOBRE `$preguntas`, no otra lista: cada grupo lleva los
     * INDICES de sus filas y el parcial pinta las mismas filas numeradas de
     * siempre — con lo que el resumen («2 observaciones — preguntas 7, 12»)
     * sigue apuntando bien. La regla del reparto es la misma que en el EPP y en
     * la pantalla (`agrupar()` de respuestas.js): el primer grupo que reclama
     * una pregunta se la queda, y lo que ningun grupo reclama sale al final,
     * sin rotulo. Sin `config.groups` hay un solo grupo anonimo con todo, que
     * el parcial pinta exactamente como pintaba la lista plana.
     *
     * La imagen es un data URI que viaja en la config —DomPDF lo imprime sin
     * salir a la red— y solo se acepta esa forma: una URL externa en un PDF de
     * seguridad no es una opcion.
     *
     * @param  array<int, array>  $preguntas  ya numeradas, en su orden final
     * @return array<int, array{titulo: ?string, image: ?string, indices: array<int, int>}>
     */
    protected static function grupos(array $config, array $preguntas): array
    {
        $declarados = is_array($config['groups'] ?? null) ? $config['groups'] : [];

        $todos = array_keys($preguntas);

        if ($declarados === []) {
            return [['titulo' => null, 'image' => null, 'indices' => $todos]];
        }

        $porTexto = [];

        foreach ($preguntas as $indice => $pregunta) {
            if ($pregunta['texto'] !== null) {
                $porTexto[$pregunta['texto']] ??= $indice;
            }
        }

        $libres = array_flip($todos);
        $grupos = [];

        foreach ($declarados as $grupo) {
            $indices = [];

            foreach (Catalogo::valores($grupo['items'] ?? []) as $texto) {
                $indice = $porTexto[$texto] ?? null;

                if ($indice !== null && isset($libres[$indice])) {
                    $indices[] = $indice;
                    unset($libres[$indice]);
                }
            }

            if ($indices === []) {
                continue;
            }

            $imagen = $grupo['image'] ?? null;

            $grupos[] = [
                'titulo' => \App\Support\TextoTraducible::de($grupo['name'] ?? null) ?: null,
                'image'  => is_string($imagen) && str_starts_with($imagen, 'data:image/') ? $imagen : null,
                'indices' => $indices,
            ];
        }

        // Lo que no reclamo ningun grupo —incluido lo contestado fuera de
        // catalogo— al final y sin rotulo: nunca fuera del papel.
        if ($libres !== []) {
            $grupos[] = ['titulo' => null, 'image' => null, 'indices' => array_keys($libres)];
        }

        return $grupos;
    }

    /**
     * Una pregunta lista para imprimir.
     *
     * El tono se calcula sobre la ETIQUETA que se va a imprimir y no sobre el
     * valor crudo. Para todo lo que escriben la pantalla y el migrador son la
     * misma cadena —las dos guardan la etiqueta del catalogo, nunca el numero—,
     * asi que el color del papel y el contador de la ficha del plan dicen lo
     * mismo. Donde podrian separarse es en un valor que ninguno de los dos
     * produce, y ahi manda lo que el lector tiene delante: la palabra impresa.
     */
    protected static function pregunta(
        ?string $texto,
        mixed $bruta,
        array $posibles,
        bool $fueraDeCatalogo,
        array $config = [],
    ): array {
        $valor = self::etiqueta($bruta, $posibles);

        // El TONO se pregunta por el valor guardado, que es la clave del
        // catalogo; el ROTULO es lo que se imprime, y puede venir traducido.
        // Antes las dos cosas eran la misma cadena porque el tono se adivinaba
        // del castellano, y esa adivinanza pintaba con el ✔ el «Rechazado» de
        // cualquier formato que no hablara como los cuatro migrados.
        $tono      = $valor === null ? null : app(FormFindingsService::class)->tono($valor, $config);
        $respuesta = $valor === null ? null : Catalogo::etiqueta($config['answers'] ?? null, $valor);

        return [
            // Lo pone `datos()` al final, cuando ya sabe el orden definitivo.
            'numero'      => 0,
            'texto'       => $texto === null ? null : Catalogo::etiqueta($config['questions'] ?? null, $texto),
            'respuesta'   => $respuesta,
            'tono'        => $tono,
            'observacion' => $tono === FormFindingsService::MALA,
            'fuera_de_catalogo' => $fueraDeCatalogo,
        ];
    }

    /**
     * La respuesta como se lee, o null si no se contesto.
     *
     * Lo guardado ya es la etiqueta («Si», «No»): la pantalla emite el texto
     * del catalogo y `LegacyFormMapper` traduce el numero de la v1 antes de
     * guardar, precisamente para que un cambio de orden en el catalogo no
     * cambie el significado de lo ya firmado. Aqui se respeta tal cual — no se
     * traduce «Si» a «Yes» en un PDF en ingles porque el enunciado al lado
     * sigue estando en castellano (el catalogo es de un solo idioma,
     * docs/FORMATOS.md §5.2) y media frase traducida se lee peor que la frase
     * entera en un idioma.
     *
     * El 1 y el 0 pelados son la excepcion, y solo cuando el catalogo del campo
     * no los declara como respuesta posible: es lo que hay en algun volcado
     * hecho a mano contra la base vieja, donde `answer` es la columna numerica
     * de `f2_document_answers`. La equivalencia no se inventa, es la de
     * `LegacyFormMapper::RESPUESTAS_PTF` (1 → Si, 0 → No) — y **no** es la
     * posicion en la lista de respuestas, que daria justo lo contrario. Un «1»
     * impreso en la columna de respuestas de un documento de seguridad no dice
     * nada a quien lo lee.
     */
    protected static function etiqueta(mixed $bruta, array $posibles): ?string
    {
        if ($bruta === null || is_array($bruta)) {
            return null;
        }

        if (is_bool($bruta)) {
            return $bruta ? __('global.yes') : __('global.no');
        }

        $texto = trim((string) $bruta);

        if ($texto === '') {
            return null;
        }

        // El catalogo del campo manda: una respuesta que el formato declara se
        // imprime tal como se configuro, aunque se llame «0».
        if (in_array($texto, $posibles, true)) {
            return $texto;
        }

        return match ($texto) {
            '1'     => __('global.yes'),
            '0'     => __('global.no'),
            default => $texto,
        };
    }

    /**
     * Los pares enunciado/respuesta guardados, en el orden en que se guardaron.
     *
     * El banco de preguntas es el unico compuesto que guarda la lista entera en
     * UNA respuesta, sin `row_index` por pregunta
     * (`FormSubmissionService::responder()`), pero se recorre la coleccion
     * ordenada igual: una entrega puede tener mas de una fila por un guardado
     * repetido, y el orden no puede depender de lo que devuelva el motor.
     *
     * Del par se leen dos cosas y nada mas. Ni `pregunta_id` ni ninguna otra
     * clave interna entran aqui: no se filtran despues, es que no se leen.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\FormAnswer>  $respuestas
     * @return array<int, array{texto: ?string, respuesta: mixed}>
     */
    protected static function pares(Collection $respuestas): array
    {
        $pares = [];

        $ordenadas = $respuestas->sortBy(
            fn (FormAnswer $r) => [(int) $r->row_index, (int) $r->getKey()],
        );

        foreach ($ordenadas as $respuesta) {
            $valor = $respuesta->value_json;

            if (! is_array($valor)) {
                continue;
            }

            // Lo normal es la lista; se admite el par suelto porque una
            // respuesta guardada fila a fila —como la guardan los demas
            // compuestos— seria igual de valida y perderla no tiene excusa.
            foreach (array_is_list($valor) ? $valor : [$valor] as $par) {
                if (! is_array($par)) {
                    continue;
                }

                $pares[] = [
                    'texto'     => self::texto(self::primera($par, self::CLAVES_PREGUNTA)),
                    'respuesta' => self::primera($par, self::CLAVES_RESPUESTA),
                ];
            }
        }

        return $pares;
    }

    /** El valor de la primera clave que traiga el par. */
    protected static function primera(array $par, array $claves): mixed
    {
        foreach ($claves as $clave) {
            if (array_key_exists($clave, $par)) {
                return $par[$clave];
            }
        }

        return null;
    }

    /** Un catalogo de la config como lista de textos, sin huecos. */
    protected static function catalogo(array $config, string $clave): array
    {
        // VALORES, no rotulos: es lo que casa con lo guardado en la respuesta y
        // con las 14 000 entregas migradas. El rotulo —que puede venir traducido—
        // se resuelve al imprimir, en `pregunta()`. Confundirlos haria que el
        // papel en ingles no encontrara ninguna respuesta.
        return Catalogo::valores($config[$clave] ?? null);
    }

    /** Texto util o null: el papel no distingue «no puesto» de «puesto en blanco». */
    protected static function texto(mixed $valor): ?string
    {
        if ($valor === null || is_array($valor) || is_bool($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
