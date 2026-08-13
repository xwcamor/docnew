<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator; // Using Bootstrap Paginate
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

}