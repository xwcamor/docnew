<?php

namespace App\Support;

/**
 * Un texto que escribe el CLIENTE y que hay que leer en el idioma de quien mira.
 *
 * QUE PROBLEMA RESUELVE
 * ---------------------
 * Los textos del PRODUCTO viven en `resources/lang/{es,en}` y añadir un idioma
 * es crear un directorio. Los textos del CLIENTE —el nombre de un equipo de
 * proteccion, una pregunta del Pare y Tome 5, el rotulo de una banda de riesgo—
 * no tienen ese camino: se guardan en el `config` del campo como cadenas
 * sueltas, y una cadena suelta no se puede traducir.
 *
 * Hasta aqui eso se resolvia duplicando la clave: `severity_labels` y
 * `severity_labels_en`, `label_es` y `label_en`. Con dos idiomas cuela; con el
 * tercero hay que tocar el esquema, el migrador, el editor y las pantallas, y
 * eso es exactamente lo que no puede pasar.
 *
 * LA FORMA
 * --------
 * Cualquier texto del cliente admite dos formas, y las dos son validas para
 * siempre:
 *
 *     "Casco de seguridad"                        ← una sola lengua
 *     {"es": "Casco", "en": "Hard hat", "pt": …}  ← una por idioma
 *
 * La primera es lo que hay hoy en los cuatro formatos migrados y en las 14 000
 * entregas: **no se toca nada**. La segunda no necesita ni columna nueva ni
 * migracion —un idioma mas es una clave mas— y por eso el mecanismo es este y
 * no otra pareja de columnas.
 *
 * EL RESPALDO, EN ORDEN, Y POR QUE NO DEVUELVE VACIO NUNCA
 * --------------------------------------------------------
 * Un formato de seguridad que se lee en obra no puede tener un hueco donde va
 * el nombre de un equipo porque nadie tradujo esa linea. Asi que: el idioma
 * pedido, el de respaldo de la aplicacion, y si no, el primero que haya escrito.
 * Un rotulo en otro idioma dice infinitamente mas que un hueco.
 */
class TextoTraducible
{
    /**
     * El texto en el idioma que toca.
     *
     * @param  mixed  $valor  cadena, mapa por idioma, o cualquier otra cosa
     */
    public static function de(mixed $valor, ?string $locale = null): string
    {
        if (is_string($valor)) {
            return $valor;
        }

        if (! is_array($valor) || $valor === []) {
            // Un numero es un rotulo legitimo (las severidades «1..5» de la
            // matriz). Lo demas —null, objetos, listas— no es un texto.
            return is_scalar($valor) ? (string) $valor : '';
        }

        // Una LISTA no es un mapa por idioma. Llega cuando alguien mete una
        // lista donde iba un texto, y devolver su primer elemento seria
        // inventarse un rotulo; se dice que no hay texto y quien llama decide.
        if (array_is_list($valor)) {
            return '';
        }

        $candidatos = array_filter([
            $locale ?? app()->getLocale(),
            config('app.fallback_locale'),
        ]);

        foreach ($candidatos as $idioma) {
            $texto = $valor[$idioma] ?? null;

            if (is_string($texto) && trim($texto) !== '') {
                return $texto;
            }
        }

        // Ninguno de los dos: el primero que alguien haya escrito. Un rotulo en
        // otro idioma dice mas que un hueco en un documento de seguridad.
        foreach ($valor as $texto) {
            if (is_string($texto) && trim($texto) !== '') {
                return $texto;
            }
        }

        return '';
    }

    /**
     * Si este valor lleva traducciones dentro (y no es una cadena a secas).
     *
     * Lo usa el editor para saber si tiene que abrir la fila de idiomas, y las
     * pruebas para no dar por buena una config a medio migrar.
     */
    public static function esMapa(mixed $valor): bool
    {
        return is_array($valor) && $valor !== [] && ! array_is_list($valor);
    }

    /**
     * Un mapa de idiomas a partir de columnas fijas mas la columna `_i18n`.
     *
     * Es la pieza de los NOMBRES: el nombre de un campo, de una seccion, de un
     * formato y de un rol aprobador vive en parejas de columnas (`label_es` /
     * `label_en`) que no se pueden tocar —las leen en crudo los exports, los
     * filtros y las ordenaciones— y los demas idiomas viven en la columna JSON
     * `*_i18n`. Aqui se funden para leerse como un solo mapa.
     *
     * LAS COLUMNAS MANDAN EN SU IDIOMA. Si el JSON trajera un `es` —por la API,
     * por un volcado— se ignora: cada idioma tiene exactamente UN sitio donde
     * vivir, o acabaria habiendo una copia rancia que gana a la editada.
     *
     * @param  array<string, mixed>  $columnas  ['es' => name_es, 'en' => name_en]
     * @param  mixed  $extra  la columna `*_i18n`, si la hay
     * @return array<string, string>
     */
    public static function fundir(array $columnas, mixed $extra): array
    {
        $limpiar = fn (array $mapa) => array_filter(
            $mapa,
            fn ($texto) => is_string($texto) && trim($texto) !== '',
        );

        $extra = self::esMapa($extra) ? $limpiar($extra) : [];

        // `+` conserva la clave de la izquierda: la columna gana en su idioma.
        return $limpiar($columnas) + array_diff_key($extra, $columnas);
    }
}
