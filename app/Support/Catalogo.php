<?php

namespace App\Support;

/**
 * Una lista configurable de un campo: respuestas, equipos, herramientas,
 * preguntas, actividades.
 *
 * LAS DOS FORMAS, Y POR QUE HAY DOS
 * ---------------------------------
 * Todo lo que el cliente define en `config` admite la forma corta y la larga:
 *
 *     "Conforme"                                          ← forma corta
 *     {"value": "No conforme", "tone": "bad",             ← forma larga
 *      "label": {"en": "Non-compliant"}}
 *
 * La corta es lo que hay hoy en los cuatro formatos migrados y en las 14 000
 * entregas, y **sigue siendo valida para siempre**: una cadena es su propio
 * valor y su propio rotulo. La larga añade las dos cosas que la cadena no puede
 * llevar dentro —el tono y las traducciones— sin cambiar ni una fila.
 *
 * `value` ES LO QUE SE GUARDA, Y NO SE TRADUCE NUNCA. Es la clave de la
 * respuesta en `form_answers`, la que casa el PDF con su columna y la que
 * escribieron las 14 000 entregas migradas. Traducirla convertiria el mismo
 * documento en dos documentos distintos segun el idioma de quien lo llenara.
 * Lo que cambia con el idioma es el ROTULO, que es lo unico que se lee.
 *
 * EL TONO SE DECLARA, NO SE ADIVINA
 * ---------------------------------
 * `FormFindingsService::tono()` clasificaba una respuesta mirando si el texto
 * empieza por «no». Con Conforme/No conforme y Cumple/No cumple funciona; con
 * el catalogo de otra empresa se rompe en silencio y en la peor direccion:
 *
 *   - «Rechazado», «Malo», «Deficiente», «Fail» salen clasificados como
 *     CONFORMES. Y como ninguna respuesta del catalogo cuenta como negativa, la
 *     pastilla no tiene a donde ir: **no hay forma de registrar el fallo**.
 *   - «Normal» empieza por «no» y se cuenta como NO CONFORMIDAD.
 *
 * De ese tono cuelgan el contador de observaciones que el supervisor lee, los
 * simbolos del PDF, el color de la pastilla, los campos de medida de correccion
 * y el relleno de «no aplica» al cerrar. Una adivinanza en castellano no puede
 * decidir eso, asi que el tono es un dato del catalogo y la heuristica se queda
 * sólo como valor por defecto para lo que ya estaba escrito.
 *
 * TODO ESTO ES UNA VISTA, NO UNA COPIA. Lo que se guarda en `config` es lo que
 * el cliente escribio, en la forma que lo escribio. Aqui sólo se lee.
 */
class Catalogo
{
    /**
     * Los tonos que un catalogo puede declarar.
     *
     * Son los mismos tres que `FormFindingsService` sabe contar y que el PDF
     * sabe dibujar, y por eso la lista vive junta: un tono inventado no tendria
     * ni simbolo ni color ni cuenta, y saldria en blanco en el papel.
     */
    public const TONOS = ['ok', 'bad', 'na'];

    /**
     * La lista normalizada, cada entrada con su valor, su rotulo y su tono.
     *
     * @return array<int, array{value: string, label: string, tone: ?string}>
     */
    public static function entradas(mixed $crudo, ?string $locale = null): array
    {
        $salida = [];

        foreach (self::crudas($crudo) as $entrada) {
            if (! is_array($entrada)) {
                // Forma corta: la cadena es su valor y su rotulo.
                if ($entrada === null || is_bool($entrada) || is_array($entrada)) {
                    continue;
                }

                $valor = (string) $entrada;

                $salida[] = ['value' => $valor, 'label' => $valor, 'tone' => null];

                continue;
            }

            // Forma larga. Sin `value` no hay entrada: no se puede guardar una
            // respuesta que no tiene con que identificarse, y adivinarla desde
            // el rotulo la ataria al idioma en que se configuro.
            $valor = $entrada['value'] ?? null;

            if (! is_scalar($valor) || (string) $valor === '') {
                continue;
            }

            $valor  = (string) $valor;
            $rotulo = TextoTraducible::de($entrada['label'] ?? null, $locale);
            $tono   = $entrada['tone'] ?? null;

            $salida[] = [
                'value' => $valor,
                // Sin rotulo se lee el valor, que es lo que se leia antes de que
                // existieran los rotulos. Un hueco donde va el nombre de un
                // equipo no es aceptable en un documento de seguridad.
                'label' => $rotulo !== '' ? $rotulo : $valor,
                'tone'  => in_array($tono, self::TONOS, true) ? $tono : null,
            ];
        }

        return $salida;
    }

    /**
     * Sólo los valores, que es lo que se guarda y lo que casa con lo guardado.
     *
     * @return array<int, string>
     */
    public static function valores(mixed $crudo): array
    {
        return array_column(self::entradas($crudo), 'value');
    }

    /** El rotulo de un valor guardado; el propio valor si el catalogo no lo dice. */
    public static function etiqueta(mixed $crudo, mixed $valor, ?string $locale = null): string
    {
        $valor = is_scalar($valor) ? (string) $valor : '';

        foreach (self::entradas($crudo, $locale) as $entrada) {
            if ($entrada['value'] === $valor) {
                return $entrada['label'];
            }
        }

        // Una respuesta que ya no esta en el catalogo se sigue leyendo. Pasa
        // cuando el formato cambia de version y hay entregas viejas: borrar el
        // texto seria reescribir un documento firmado.
        return $valor;
    }

    /**
     * El tono que el catalogo DECLARA para un valor, o null si no dice nada.
     *
     * Null no significa «neutro»: significa «no lo dijo», y entonces decide la
     * heuristica de `FormFindingsService::tono()`. Los dos casos tienen que
     * poder distinguirse — «no aplica» es un tono declarado y es distinto de no
     * haber declarado ninguno.
     */
    public static function tonoDeclarado(mixed $crudo, mixed $valor): ?string
    {
        $valor = is_scalar($valor) ? (string) $valor : '';

        foreach (self::entradas($crudo) as $entrada) {
            if ($entrada['value'] === $valor) {
                return $entrada['tone'];
            }
        }

        return null;
    }

    /** Si alguna entrada declara su tono. Con una basta para no adivinar el resto. */
    public static function declaraTonos(mixed $crudo): bool
    {
        foreach (self::entradas($crudo) as $entrada) {
            if ($entrada['tone'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * La lista en bruto, tolerando el atajo numerico.
     *
     * El seeder de demostracion guarda `['severidades' => 5]` en vez de la lista
     * de cinco, y sin esto el campo no se podria pintar. Es la misma tolerancia
     * que `catalogo()` en el lado de la pantalla, y las dos tienen que decir lo
     * mismo o el numero de columnas del PDF no cuadraria con el de la tablet.
     */
    protected static function crudas(mixed $crudo): array
    {
        if (is_array($crudo)) {
            return $crudo;
        }

        if (is_int($crudo) || (is_string($crudo) && ctype_digit($crudo))) {
            $n = (int) $crudo;

            return $n > 0 ? array_map('strval', range(1, $n)) : [];
        }

        return [];
    }
}
