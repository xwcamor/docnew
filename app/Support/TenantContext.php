<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * En que workspace esta trabajando ahora mismo quien hace la peticion.
 *
 * Un admin siempre trabaja en el suyo y no hay mas que hablar. El super no
 * tiene workspace propio, y hasta ahora eso significaba dos cosas malas a la
 * vez: veia TODOS los workspaces mezclados, y lo que creaba nacia sin dueño
 * (`tenant_id` null). De ahi salieron las empresas huerfanas que hubo que
 * rescatar con una migracion y la fuga de los ficheros exportados.
 *
 * Ahora el super puede ENTRAR en un workspace. Mientras esta dentro, todo lo
 * que ve y lo que crea es de ese workspace — se comporta como su admin. Al
 * salir vuelve a la consola, donde ve todo y donde crea los registros
 * compartidos del catalogo (los de `tenant_id` null, que son los que usan
 * todos los workspaces sin tener que recrearlos).
 *
 * El valor vive en sesion, asi que muere con ella. Todo el que necesite saber
 * "de quien es esto" pregunta aqui y no a `$user->tenant_id`, que es la
 * respuesta incompleta.
 */
class TenantContext
{
    public const CLAVE = 'acting_tenant_id';

    /**
     * El workspace efectivo del usuario dado (o del autenticado).
     *
     * `null` significa "sin workspace": para un admin no deberia pasar nunca,
     * y para un super quiere decir que esta en la consola, fuera de todos.
     */
    public static function actual(?User $user = null): ?int
    {
        $user ??= auth()->user();

        if ($user === null) {
            return null;
        }

        // Solo el super puede estar "dentro de otro": para cualquier otro rol la
        // sesion no manda, manda su columna. Asi un valor colado en sesion no
        // saca a nadie de su workspace.
        if (method_exists($user, 'hasRole') && $user->hasRole('super')) {
            $enSesion = session(self::CLAVE);

            return $enSesion !== null ? (int) $enSesion : null;
        }

        return $user->tenant_id;
    }

    /** ¿Hay un super trabajando dentro de un workspace ajeno ahora mismo? */
    public static function dentroDeUno(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null
            && method_exists($user, 'hasRole')
            && $user->hasRole('super')
            && session(self::CLAVE) !== null;
    }

    /** El workspace en el que se ha entrado, para pintarlo en la franja. */
    public static function workspace(): ?Tenant
    {
        $id = session(self::CLAVE);

        return $id === null ? null : Tenant::find($id);
    }

    public static function entrar(Tenant $tenant): void
    {
        session([self::CLAVE => $tenant->id]);
    }

    public static function salir(): void
    {
        session()->forget(self::CLAVE);
    }
}
