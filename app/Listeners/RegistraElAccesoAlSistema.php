<?php

namespace App\Listeners;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Quien entra al sistema, cuando y desde donde.
 *
 * No se registraba. El historial guardaba con detalle **lo que cada uno
 * cambia** —con sus valores de antes y de despues, su IP y su navegador— y no
 * guardaba nada de las sesiones: quien entro, a que hora, desde que IP, ni
 * cuantas veces alguien fallo la contrasena antes de acertar.
 *
 * Es la primera pregunta de cualquier revision de accesos, y en un sistema
 * donde una cuenta puede cerrar planes de seguridad no es una curiosidad. El
 * caso que lo hace evidente: un plan modificado a las tres de la manana con el
 * usuario de un supervisor. Con esto se puede ver si esa sesion se abrio desde
 * la IP de siempre o desde la otra punta del pais.
 *
 * Cuatro eventos, y el tercero es el que mas dice:
 *
 *   - `login`          entro
 *   - `logout`         salio, si es que cerro sesion (la mayoria no lo hace)
 *   - `login_failed`   contrasena equivocada, con el correo que se intento
 *   - `login_lockout`  demasiados intentos: la cuenta se freno
 *
 * **Nunca se guarda la contrasena.** El evento `Failed` de Laravel trae las
 * credenciales enteras, con la contrasena en claro dentro, y de ahi solo se
 * saca el identificador con el que se intento entrar.
 */
class RegistraElAccesoAlSistema
{
    public function entro(Login $evento): void
    {
        // Una cuenta desactivada con la contrasena correcta SI dispara `Login`:
        // el controlador deja pasar el intento y la echa en la linea siguiente
        // (ver LoginController::loginAccess). Esa persona no entro, y anotar que
        // entro seria falso — y ademas dejaria el par login+logout mas
        // inofensivo del mundo donde en realidad hubo un acceso rechazado.
        if ($this->estaDesactivado($evento->user)) {
            $this->anotar('login_blocked', $evento->user);

            return;
        }

        $this->anotar('login', $evento->user);
    }

    public function salio(Logout $evento): void
    {
        // Sin usuario: cierre de sesion por caducidad o por otro guard.
        if ($evento->user === null) {
            return;
        }

        // El `logout` inmediato con el que el controlador echa al desactivado.
        // Ya quedo contado como `login_blocked`; contarlo dos veces sobra.
        if ($this->estaDesactivado($evento->user)) {
            return;
        }

        $this->anotar('logout', $evento->user);
    }

    public function fallo(Failed $evento): void
    {
        $this->anotar('login_failed', $evento->user, $this->quienLoIntento($evento->credentials));
    }

    public function seFreno(Lockout $evento): void
    {
        $intento = $this->quienLoIntento((array) $evento->request->only('email'));

        // Se busca a quien pertenece el correo frenado. No es cosmetico: el
        // historial que ve el admin de un workspace esta acotado a la gente de
        // SU workspace (AuditLogController::index), asi que una fila sin
        // usuario solo la ve el super — y el freno de una cuenta es justo lo
        // que el admin tiene que enterarse. Si el correo no existe, la fila se
        // queda suelta y con eso basta: no hay a quien avisar.
        // Sin los scopes de listado: esto es una busqueda, no un listado. Con
        // ellos puestos, el freno de una cuenta super o de una dada de baja se
        // quedaria sin dueno justo cuando mas interesa saber de quien es.
        $usuario = $intento === null
            ? null
            : User::withoutGlobalScopes()->where('email', $intento)->first();

        $this->anotar('login_lockout', $usuario, $intento);
    }

    /**
     * Si la cuenta esta dada de baja.
     *
     * Se pregunta con cuidado porque por aqui pasa cualquier `Authenticatable`,
     * no solo App\Models\User: un guard distinto podria traer algo sin esa
     * columna, y en ese caso lo correcto es tratarlo como activo.
     */
    protected function estaDesactivado(?object $usuario): bool
    {
        return $usuario instanceof User && $usuario->is_active === false;
    }

    /**
     * El identificador con el que se intento entrar. **Nunca la contrasena.**
     *
     * Las credenciales llegan enteras y con la contrasena en claro dentro. Se
     * listan las claves que se pueden anotar en vez de excluir las que no: una
     * lista de exclusiones se queda corta el dia que alguien anade un campo.
     */
    protected function quienLoIntento(array $credenciales): ?string
    {
        foreach (['email', 'username', 'name'] as $clave) {
            if (filled($credenciales[$clave] ?? null)) {
                return substr((string) $credenciales[$clave], 0, 255);
            }
        }

        return null;
    }

    /**
     * Una fila en el historial, con el mismo toggle que el resto.
     *
     * Si el super apago el historial desde Ajustes, esto tampoco escribe: seria
     * incoherente que apagarlo dejara de registrar quien edita un plan y
     * siguiera registrando quien entra.
     *
     * Va al modulo `users` a proposito, y no a uno propio: es donde el admin
     * del workspace ya puede mirar, y lo que se cuenta es de un usuario.
     */
    protected function anotar(string $evento, ?object $usuario, ?string $intento = null): void
    {
        if (! Setting::getBool('features.audit_log_enabled', true)) {
            return;
        }

        AuditLog::create([
            'user_id'        => $usuario?->getAuthIdentifier(),
            'event'          => $evento,
            'auditable_type' => $usuario ? $usuario::class : User::class,
            'auditable_id'   => $usuario?->getAuthIdentifier(),
            'module'         => 'users',
            'old_values'     => null,
            // El correo TAL COMO se escribio, que no siempre es el de nadie: un
            // fallo puede venir de un correo que no existe, y saber con que se
            // esta probando es medio diagnostico. Nunca la contrasena.
            'new_values'     => $intento === null ? null : ['intento' => $intento],
            'url'            => null,
            'ip_address'     => request()?->ip(),
            'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
        ]);
    }
}
