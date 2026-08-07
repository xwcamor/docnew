<?php

namespace App\Support;

/**
 * LikeQuery — helper para queries LIKE seguras contra wildcards en input.
 *
 * Los caracteres `%` y `_` actúan como comodines SQL incluso cuando el valor
 * va por binding parametrizado. Si un usuario busca literalmente `50%`,
 * `LIKE '%50%%'` matchea cualquier cosa con "50" — comportamiento inesperado.
 *
 * Solución estándar: escapar `\`, `%` y `_` en el valor del usuario y usar
 * `LIKE ? ESCAPE '\\'` (Postgres/MySQL ambos lo soportan).
 *
 * Uso:
 *   $needle = LikeQuery::escape($request->name);
 *   $q->where('name', 'like', '%' . $needle . '%')->whereRaw("name LIKE ? ESCAPE '\\\\'", [...]);
 *
 * O el helper combinado `whereLikeContains` que arma toda la cláusula.
 */
final class LikeQuery
{
    /**
     * Escapa caracteres especiales de LIKE (`\`, `%`, `_`) en el valor del
     * usuario para que actúen como literales y no como comodines.
     *
     * Orden importante: backslash primero (sino se duplica el de los siguientes).
     */
    public static function escape(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }

    /**
     * Devuelve `%escaped%` para usar como needle de "contains" en LIKE.
     */
    public static function contains(string $value): string
    {
        return '%' . self::escape($value) . '%';
    }
}
