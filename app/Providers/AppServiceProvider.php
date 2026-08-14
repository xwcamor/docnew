<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator; // Using Bootstrap Paginate
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

// Models and Observers
use App\Listeners\RegistraElAccesoAlSistema;
use App\Models\SystemModule;
use App\Observers\SystemModuleObserver;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forzarHttpsFueraDeLocal();

        // ── `route:list` de vuelta ────────────────────────────────────────
        // laravel-localization hereda la `$signature` de RouteListCommand, asi
        // que su comando se registra como `route:list` y pisa al de Laravel —
        // y encima pide un argumento `locale` que esa firma no declara.
        //
        // Se sustituye el comando del paquete por uno que declara la firma
        // que le faltaba. Ver App\Console\Commands\RouteTranslationsListCommand.
        $this->app->singleton(
            'laravellocalizationroutecache.list',
            \App\Console\Commands\RouteTranslationsListCommand::class,
        );

        // ── Candado de producción ─────────────────────────────────────────
        // En producción se PROHÍBEN los comandos destructivos de BD
        // (migrate:fresh, migrate:refresh, migrate:reset, db:wipe), incluso
        // con --force. En local/dev siguen libres (ahí sí se reconstruye la
        // BD con migrate:fresh --seed). Si un día se necesita de verdad en
        // prod (nunca debería), el escape es correr el comando con
        // APP_ENV=local puntualmente — decisión consciente, no un descuido.
        DB::prohibitDestructiveCommands($this->app->isProduction());

        // Call Boostrap on paginate
        Paginator::useBootstrap();

        // Register Observers
        SystemModule::observe(SystemModuleObserver::class);

        // Quien entra al sistema, cuándo y desde dónde. `Auditable` registra
        // con detalle lo que cada uno CAMBIA, y de las sesiones no guardaba
        // nada: ni quién entró ni cuántas veces se falló la contraseña antes de
        // acertar. Es la primera pregunta de cualquier revisión de accesos.
        Event::listen(Login::class,   [RegistraElAccesoAlSistema::class, 'entro']);
        Event::listen(Logout::class,  [RegistraElAccesoAlSistema::class, 'salio']);
        Event::listen(Failed::class,  [RegistraElAccesoAlSistema::class, 'fallo']);
        Event::listen(Lockout::class, [RegistraElAccesoAlSistema::class, 'seFreno']);

        // Super admin bypass: any user with role "super" passes ALL gates.
        Gate::before(function ($user, $ability) {
            return method_exists($user, 'hasRole') && $user->hasRole('super') ? true : null;
        });

        // ── API rate limiter ──────────────────────────────────────────────
        // Throttles each authenticated token (or IP for unauth requests) to
        // 60 requests/minute. Tune in production based on real usage.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });

        // ── OTP del portal compartido ─────────────────────────────────────
        // Clave por SHARE + IP (no un contador global por IP: probar dos
        // portales distintos no debe bloquearse entre sí). Protege el inbox
        // del destinatario contra spam de códigos. En vez del 429 pelado,
        // vuelve al gate con un mensaje amigable.
        // ── Solicitud de eliminación de cuenta ────────────────────────────
        // Por usuario. El controller ya es idempotente (solicitud reciente →
        // "ya registrada" sin re-notificar); esto es solo red anti-abuso y,
        // si se excede, responde con el mismo mensaje en vez de un 429 pelado.
        RateLimiter::for('deletion-request', function (Request $request) {
            return Limit::perMinute(5)
                ->by('del-req:' . ($request->user()?->id ?: $request->ip()))
                ->response(fn () => redirect()->back()->with('success', __('profile.deletion_already_requested')));
        });

        RateLimiter::for('share-otp', function (Request $request) {
            return Limit::perMinute(3)
                ->by('share-otp:' . $request->route('token') . '|' . $request->ip())
                ->response(fn () => redirect()->back()->withErrors([
                    'code' => __('sharing.otp_throttled'),
                ]));
        });

        // Share tenant_name in all views
        View::composer('*', function ($view) {
            $tenantName = null;

            if (Auth::check()) {
                // Load and rescue tenant relationship
                $user = Auth::user()->loadMissing('tenant');
                $tenantName = $user->tenant ? $user->tenant->name : null;
            }

            $view->with('tenant_name', $tenantName);
        });
    }

    /**
     * HTTPS obligatorio en todo lo que no sea la máquina de quien desarrolla.
     *
     * Había dos cabos sueltos, y los dos dependían de que alguien no se
     * equivocara al configurar el servidor:
     *
     *   - Ningún `forceScheme`. Laravel arma sus URLs a partir del esquema con
     *     el que llegó la petición, así que una petición que entre por http
     *     —un enlace viejo, un `curl`, un redirect mal puesto delante— genera
     *     un formulario de login que postea a http. Y el POST de un login sale
     *     con la contraseña dentro.
     *
     *   - `SESSION_SECURE_COOKIE` sin valor en `.env` deja
     *     `config('session.secure')` en null, que Laravel trata como «no». Sin
     *     el flag `secure`, el navegador manda la cookie de sesión también por
     *     http: basta una sola petición que caiga a http para que la sesión
     *     entera viaje en claro por la red, y con esa cookie se entra sin
     *     contraseña. La contraseña ni hace falta robarla.
     *
     * Los dos se cierran aquí, desde la aplicación, y no en el `.env`: un
     * `.env` de producción se escribe a mano una vez y nadie lo vuelve a mirar.
     * Esto no sustituye al HTTPS del servidor (Let's Encrypt, redirección
     * 80→443, HSTS — ver docs/SECURITY.md); es la red por debajo, para el día
     * que esa configuración se caiga o se despliegue en otro sitio.
     *
     * ── Por qué `local` y `testing` quedan fuera ──────────────────────────
     *
     * En desarrollo se corre con `php artisan serve` en http://localhost:8000,
     * sin certificado. Forzar https ahí manda al navegador a
     * https://localhost:8000, que no contesta: el sistema deja de arrancar en
     * la máquina de quien lo está construyendo.
     *
     * `testing` es el conjunto de pruebas, que es http://localhost y comprueba
     * redirecciones por su URL completa. La condición se escribe con los dos
     * nombres a la vista, y no con `isProduction()`, porque lo que se quiere
     * decir es «en todo lo demás, sí»: un entorno nuevo —staging, demo, una
     * preview— nace protegido sin que nadie tenga que acordarse de añadirlo.
     *
     * Es público para poder comprobarlo con cada entorno delante sin arrancar
     * el `boot()` entero: ese también registra observadores, oyentes y
     * `prohibitDestructiveCommands`, y ese último, encendido dentro de una
     * prueba, se queda encendido para el resto del proceso y deja sin base de
     * datos a todo lo que corra después. Ver tests/Feature/HttpsForzadoTest.
     */
    public function forzarHttpsFueraDeLocal(): void
    {
        if ($this->app->environment(['local', 'testing'])) {
            return;
        }

        URL::forceScheme('https');

        // Se pisa el valor de config, no el del `.env`: así el flag queda
        // puesto aunque `SESSION_SECURE_COOKIE` no esté escrita, y también
        // aunque esté escrita en `false` por descuido o copiada del `.env` de
        // desarrollo. Fuera de local no hay caso legítimo de cookie sin
        // `secure`, así que no se deja opción de apagarlo.
        config(['session.secure' => true]);
    }

}