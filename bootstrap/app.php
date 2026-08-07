<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// Laravel Plugin Languages
use Mcamara\LaravelLocalization\LaravelLocalizationServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
        ],
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Cron diario: marca subs vencidas como expired + warning emails ≤7d.
        // Corre 03:00 server time (off-peak para no competir con tráfico).
        $schedule->command('subscriptions:check-expirations')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Nota: la limpieza de downloads expirados vive en routes/console.php
        // (comando app:cleanup-expired-downloads, cada hora). Es más completa
        // que el comando viejo (grace period + dry-run + try/catch).

        // Purga soft-deleted records antiguos (según config/purge.php).
        $schedule->command('app:purge-soft-deleted')
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Automatizaciones del tenant — el "tick" busca automations vencidas
        // cada minuto y las despacha al queue. Es el único cron que necesita
        // el módulo Automations, los triggers viven en la tabla.
        // onOneServer: preventivo para multi-server deploys (hoy con 1 server
        // basta withoutOverlapping). Requiere cache driver !=array.
        $schedule->command('automations:tick')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {

        // Detrás de un túnel (cloudflared/ngrok) o load balancer: confiar en
        // los headers X-Forwarded-* para que Laravel genere URLs https.
        // Sin esto, Inertia/assets salen como http y el browser los bloquea.
        //$middleware->trustProxies(at: '*');

        // Laravel default middleware aliases
        $middleware->alias([
            'localeSessionRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            'localizationRedirect'  => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            'localeViewPath'        => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            // Spatie Permission — used to guard routes by role/permission.
            'role'                  => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'            => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission'    => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // Sanctum — token abilities (fine-grained API permissions).
            //   ability:foo       → token must have the "foo" ability
            //   abilities:a,b,c   → token must have ALL listed abilities
            'ability'               => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'abilities'             => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            // Plan feature gating — bloquea si el tenant no tiene la feature.
            //   plan_feature:api_access → solo planes con api_access habilitado
            'plan_feature'          => \App\Http\Middleware\EnforcePlanFeature::class,
            // Idempotencia de la API — reintentar no duplica.
            //   idempotency          → cabecera Idempotency-Key obligatoria
            //   idempotency:optional → se respeta si viene
            'idempotency'           => \App\Http\Middleware\EnforceIdempotency::class,
        ]);

        // Inertia: share props on every response in the web group.
        // Orden: maintenance (503 público) → enforce subscription (403 tenant
        // sin plan) → inertia (shared props). Cada middleware corta el chain
        // antes que el siguiente si decide bloquear.
        $middleware->web(append: [
            \App\Http\Middleware\MaintenanceMode::class,
            \App\Http\Middleware\EnsureUserActive::class,
            \App\Http\Middleware\EnforceSubscription::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

    })
    ->withProviders([
        LaravelLocalizationServiceProvider::class, // Laravel Localization Service Provider
        // Lee Settings de la BD al boot y override de config (app.name,
        // mail.from, session.lifetime). Editar desde UI = sin redeploy.
        \App\Providers\SettingsServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {

        // Single generic handler — matches by `instanceof` instead of type-hint
        // because Laravel 11 type-hint dispatch is not picking these up reliably.
        // Authenticated users hitting 403/404 get redirected to dashboard with
        // a friendly toast instead of the raw error page.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {

            // Skip API/JSON callers — they expect proper status codes.
            if ($request->expectsJson()) return null;
            // Skip unauthenticated — let default handler send them to login.
            if (! auth()->check()) return null;

            $is403 = $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
                  || $e instanceof \Spatie\Permission\Exceptions\UnauthorizedException
                  || (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 403);

            $is404 = $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
                  || $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException
                  || (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 404);

            if ($is403) {
                return redirect()
                    ->route('dashboard_management.dashboards.index')
                    ->with('error', 'URL prohibida — acceso restringido.');
            }

            if ($is404) {
                return redirect()
                    ->route('dashboard_management.dashboards.index')
                    ->with('error', 'La página que busca no existe.');
            }

            return null;  // any other exception falls through to default handler
        });
    })->create();