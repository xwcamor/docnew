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

// Models and Observers
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