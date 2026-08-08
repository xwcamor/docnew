<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Quien puede ver un documento de identidad completo, y como se tapa cuando no.
 *
 * El sistema anterior tenia esto resuelto y al portarlo se perdio: la ficha del
 * plan pintaba `worker.num_doc.gsub(/.(?=..)/, '*')` —todo asteriscos menos los
 * dos ultimos digitos— y solo enseñaba el numero entero, la foto y la firma a
 * los usuarios con `display_private_info`. Aqui es lo mismo, concedido por
 * perfil (`people.view_private_info`) en vez de por usuario, que es como se dan
 * los permisos en esta base.
 *
 * La regla practica: **el DNI se enmascara en el servidor, no en la pantalla**.
 * Un `v-if` en Vue esconde el numero pero lo manda igual en el JSON de Inertia,
 * y ahi lo lee cualquiera que abra las herramientas del navegador.
 */
class PrivateInfo
{
    /** Cuantos digitos del final quedan a la vista. Dos, como en el anterior. */
    private const VISIBLES = 2;

    /** Si este usuario puede ver documentos, fotos y firmas sin tapar. */
    public static function visibleFor(?User $usuario = null): bool
    {
        $usuario ??= Auth::user();

        return (bool) $usuario?->can('people.view_private_info');
    }

    /**
     * El documento tal y como debe salir para este usuario.
     *
     * Con permiso, entero. Sin permiso, `******78`. Un documento tan corto que
     * enseñar dos digitos lo delataria se tapa entero: mejor un campo inutil
     * que un dato filtrado.
     */
    public static function documento(?string $numero, ?User $usuario = null): ?string
    {
        if ($numero === null || $numero === '') {
            return $numero;
        }

        if (static::visibleFor($usuario)) {
            return $numero;
        }

        return static::enmascarar($numero);
    }

    /** El enmascarado en si, sin mirar permisos. */
    public static function enmascarar(string $numero): string
    {
        $largo = mb_strlen($numero);

        if ($largo <= self::VISIBLES) {
            return str_repeat('*', $largo);
        }

        return str_repeat('*', $largo - self::VISIBLES)
            . mb_substr($numero, -self::VISIBLES);
    }
}
