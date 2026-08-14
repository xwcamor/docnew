<?php

namespace App\Support;

/**
 * El indice ciego del documento de identidad: como se busca lo que esta cifrado.
 *
 * EL PROBLEMA
 * -----------
 * `people.num_doc` es el dato mas sensible del padron y a la vez la clave de
 * busqueda de todo el producto: en la puerta se escanea el DNI y la persona
 * entra al plan sola. Cifrar la columna a secas resuelve lo primero y rompe lo
 * segundo — el cifrado de Laravel lleva un IV aleatorio, asi que el mismo DNI
 * guardado dos veces produce dos textos distintos y `where('num_doc', ...)` no
 * encuentra nada nunca. La unica alternativa seria descifrar las 14 000 filas
 * en cada busqueda, que en obra, con guantes y a pleno sol, no es una opcion.
 *
 * LA SOLUCION
 * -----------
 * Una columna aparte, `people.num_doc_hash`, con un HMAC-SHA256 del documento.
 * Determinista —el mismo documento da siempre el mismo hash— asi que se puede
 * indexar y buscar por igualdad a coste de indice. Y de un solo sentido: del
 * hash no se vuelve al DNI.
 *
 * HMAC y no un `sha256()` pelado, y ese detalle es el que importa: un DNI
 * peruano son ocho cifras, o sea cien millones de posibilidades. Con un hash
 * sin clave, quien se lleve la tabla las prueba todas en unos segundos en un
 * portatil y recupera el padron entero. Con el HMAC hace falta ADEMAS la clave,
 * que vive en el `.env` y no en la base.
 *
 * LA CLAVE
 * --------
 * Se DERIVA del `APP_KEY`, no es el `APP_KEY`. Asi el indice y el cifrado de
 * las columnas no comparten material: filtrar uno no entrega el otro. La
 * consecuencia practica es la misma que con el cifrado — perder el `APP_KEY`
 * deja los hashes sin poder recalcular, y rotarlo obliga a recalcularlos todos
 * (ver `App\Support\CifradoEnReposo` y `docufiz:cifrar-datos-sensibles`).
 *
 * LO QUE ESTE INDICE **NO** PUEDE HACER
 * -------------------------------------
 * Solo responde a «¿es este documento exacto?». No hay «empieza por», no hay
 * «contiene» y no hay orden: son propiedades del texto en claro y el hash las
 * destruye a proposito. Donde antes habia un `LIKE '%parcial%'` ahora hay una
 * coincidencia exacta, y eso es un cambio de comportamiento visible, no un
 * detalle interno. Esta anotado en `docs/PENDIENTES.md`.
 */
class DocumentoBuscable
{
    /**
     * De donde sale la clave del indice, y por que hay un texto aqui metido.
     *
     * Es la etiqueta de derivacion. Sirve para que la clave del indice ciego
     * sea distinta de cualquier otra cosa que en el futuro se derive del mismo
     * `APP_KEY`. Lleva version: el dia que haya que cambiar como se normaliza
     * un documento, se sube a `v2` y los hashes viejos dejan de coincidir de
     * golpe —que es justo lo que se quiere saber— en vez de coincidir a medias.
     */
    private const ETIQUETA = 'docufiz:indice-ciego:num_doc:v1';

    /** @var array<string, string> clave derivada, cacheada por `APP_KEY`. */
    private static array $claves = [];

    /**
     * El documento en la forma en que se compara, que no es en la que se teclea.
     *
     * En obra el mismo DNI llega escrito de cinco maneras: «12345678» del
     * lector, «12.345.678» copiado de un Excel, «12 345 678» tecleado a mano,
     * « 12345678 » pegado con un espacio detras. En la v1 eso producia personas
     * duplicadas —el mismo trabajador dado de alta dos veces— y en la v2 los
     * formularios ya limpiaban espacios y guiones (`preg_replace('/[\s-]/')`)
     * justo por eso.
     *
     * Aqui se cierra del todo, porque el indice ciego OBLIGA a cerrarlo: dos
     * escrituras distintas del mismo documento dan dos hashes distintos y la
     * segunda no encuentra a la primera. Se quita **todo** lo que no sea letra
     * o cifra —puntos, guiones, barras, espacios— y se pasa a mayusculas.
     *
     * Las mayusculas son un cambio deliberado respecto a lo que habia: hasta
     * ahora «ab123456» y «AB123456» eran dos pasaportes distintos para la base.
     * No lo son para nadie mas, y con el indice ciego seguir fingiendo que lo
     * son solo produce el duplicado que este metodo existe para evitar.
     *
     * Lo que NO se toca son los ceros a la izquierda: «003028674» y «3028674»
     * se quedan distintos, porque en un carne de extranjeria el cero de delante
     * es parte del numero. Quitarlos fusionaria a dos personas reales, que es
     * peor que no encontrar a una.
     */
    public static function normalizar(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }

        $limpio = preg_replace('/[^\p{L}\p{N}]/u', '', $numero) ?? '';

        return $limpio === '' ? '' : mb_strtoupper($limpio);
    }

    /**
     * El valor que se guarda en `num_doc_hash` y por el que se busca.
     *
     * Devuelve `null` para lo que no es un documento (nulo o vacio despues de
     * normalizar). Un hash del texto vacio seria un valor constante que
     * agruparia a todas las filas incompletas bajo la misma clave, y encima
     * chocaria contra el indice unico.
     */
    public static function hash(?string $numero): ?string
    {
        $normalizado = static::normalizar($numero);

        if ($normalizado === null || $normalizado === '') {
            return null;
        }

        return hash_hmac('sha256', $normalizado, static::clave());
    }

    /**
     * La clave del indice, derivada del `APP_KEY` y cacheada.
     *
     * Se cachea POR `APP_KEY` y no en una variable a secas porque las pruebas
     * cambian la clave en caliente (`config(['app.key' => ...])`) para
     * comprobar justamente que con otra clave los hashes dejan de coincidir.
     * Con una cache ciega esa prueba pasaria sin comprobar nada.
     */
    private static function clave(): string
    {
        $appKey = (string) config('app.key');

        if ($appKey === '') {
            // Sin `APP_KEY` no hay nada que derivar. Se corta aqui y no se
            // inventa una clave por defecto: un indice construido con una
            // clave improvisada es un indice que hay que rehacer entero el dia
            // que aparezca la buena, y nadie se enteraria hasta entonces.
            throw new \RuntimeException(
                'APP_KEY vacio: no se puede calcular el indice ciego del documento.',
            );
        }

        if (isset(static::$claves[$appKey])) {
            return static::$claves[$appKey];
        }

        $material = str_starts_with($appKey, 'base64:')
            ? (base64_decode(substr($appKey, 7), true) ?: $appKey)
            : $appKey;

        return static::$claves[$appKey] = hash_hmac('sha256', static::ETIQUETA, $material, true);
    }
}
