<?php

namespace App\Services\FieldWork\Pdf;

use App\Services\FieldWork\FormFindingsService;

/**
 * Los simbolos de las cuadriculas del PDF, y la leyenda que los explica.
 *
 * POR QUE SIMBOLOS
 * ----------------
 * El EPP y la inspeccion de herramientas son cuadriculas: una fila por
 * trabajador o herramienta, una columna por item. Escribir «Conforme» entero en
 * cada celda gasta el ancho de la palabra mas larga por columna —y son veinte
 * columnas— asi que o se ponen tres columnas por hoja o se lee a lupa. En el
 * papel de la v1 tampoco esta escrito: hay una marca y una leyenda al pie
 * (`show_pdf_page1.erb` pinta `check.png`, `equis.png` y `minus.png`).
 *
 * Lo pidio el dueño del producto tal cual: «poner una leyenda y poner algun
 * simbolo como un check, un guion y una equis».
 *
 * POR QUE NO ES ZAPFDINGBATS
 * --------------------------
 * Es lo que parece la respuesta —es una de las core fonts de PDF y trae el
 * check— pero DomPDF no la sirve: pedirla por `font-family` y escribir el byte
 * de la marca saca los digitos encimados, y escribir el caracter Unicode saca
 * un interrogante. Comprobado generando un PDF de prueba, no deducido.
 *
 * Lo que si funciona es DejaVu Sans, que **viaja dentro de dompdf**
 * (`vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf`) y trae U+2714 y U+2718. No
 * hay que instalar ninguna fuente ni salir a la red: por eso los simbolos van
 * en un `<span class="sym">` con esa familia y el resto del documento se queda
 * en Helvetica, que aprieta menos en las tablas.
 *
 * NUNCA EL SIMBOLO SOLO
 * ---------------------
 * La leyenda es obligatoria, no un adorno: una cuadricula de marcas sin decir
 * que significan es ilegible para quien la abre por primera vez, y el color
 * tampoco vale por si mismo —una fotocopia en blanco y negro lo pierde—.
 * Simbolo, color y leyenda; los tres.
 */
final class Simbolos
{
    /** Bien. U+2714, la marca gruesa: la fina se pierde a 7pt. */
    public const OK = '✔';

    /** Mal. U+2718, la equis gruesa, por lo mismo. */
    public const MALA = '✘';

    /** No aplica. Un guion de verdad (U+2013), que es lo que hay en el papel. */
    public const NO_APLICA = '–';

    /**
     * Sin responder, que NO es lo mismo que «no aplica».
     *
     * «No aplica» es una decision de quien inspecciono; el hueco es que nadie
     * miro. Pintarlos igual —los dos con guion— convertiria un olvido en una
     * decision, que en un formato de seguridad es justo lo que no puede pasar.
     */
    public const SIN_RESPONDER = '?';

    /** El simbolo que le toca a un tono de `FormFindingsService::tono()`. */
    public static function de(?string $tono): string
    {
        return match ($tono) {
            'ok'                        => self::OK,
            FormFindingsService::MALA   => self::MALA,
            FormFindingsService::NO_APLICA => self::NO_APLICA,
            default                     => self::SIN_RESPONDER,
        };
    }

    /**
     * La leyenda, con las palabras del propio formato.
     *
     * Las respuestas salen de `config.answers` de la plantilla —«Conforme / No
     * aplica / No conforme» en el EPP, «Cumple / No cumple / No aplica» en las
     * herramientas— y se clasifican con la MISMA regla que cuenta las no
     * conformidades. Asi la leyenda dice lo que el cliente escribio en su
     * plantilla y no una traduccion nuestra, y un formato que alguien defina
     * mañana con otras palabras sale explicado sin tocar nada.
     *
     * Si la plantilla no declara respuestas —puede pasar en lo migrado— caen
     * las tres genericas, que es mejor que una leyenda vacia.
     *
     * @param  list<string>  $respuestas  `config.answers` de la plantilla
     * @return list<array{simbolo: string, texto: string, tono: string}>
     */
    public static function leyenda(array $respuestas): array
    {
        $reglas = app(FormFindingsService::class);
        $porTono = [];

        foreach ($respuestas as $respuesta) {
            $texto = trim((string) $respuesta);

            if ($texto === '') {
                continue;
            }

            // La primera de cada tono manda: si una plantilla trae «No aplica»
            // y «N/A», la leyenda no repite el mismo guion dos veces.
            $porTono[$reglas->tono($texto)] ??= $texto;
        }

        $entradas = [];

        // En el orden en que se lee una inspeccion: lo bueno, lo que no toca,
        // lo malo. Y el hueco al final, que no es una respuesta.
        foreach (['ok', FormFindingsService::NO_APLICA, FormFindingsService::MALA] as $tono) {
            $texto = $porTono[$tono] ?? __('form_submissions.pdf.legend.' . $tono);

            $entradas[] = [
                'simbolo' => self::de($tono),
                'texto'   => (string) $texto,
                'tono'    => $tono,
            ];
        }

        $entradas[] = [
            'simbolo' => self::SIN_RESPONDER,
            'texto'   => (string) __('form_submissions.pdf.legend.unanswered'),
            'tono'    => 'sin',
        ];

        return $entradas;
    }
}
