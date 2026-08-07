<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceSubscription — bloquea acceso a tenants SUSPENDIDOS.
 *
 * "Sin suscripción" NO es bloqueo — es el plan `free`, el piso usable. Un
 * plan pago que vence simplemente degrada el tenant a `free`, no lo lockea
 * (patrón estándar de SaaS). El ÚNICO bloqueo duro es la suspensión
 * deliberada (pago en disputa, fraude) — ver Tenant::isSuspended().
 *
 * Reglas:
 *   - super: bypass siempre (puede gestionar suscripciones de otros).
 *   - User sin tenant (system_user, api): bypass (no es un tenant business).
 *   - Tenant no suspendido (free, trial, paid, cancelado-vigente): continúa.
 *   - Tenant suspendido: bloqueado con página 403.
 *
 * Rutas exentas del bloqueo (siempre accesibles):
 *   - logout, login, profile
 *   - Routes de billing/subscription (para que admin pueda renovar)
 *   - Página de "tu plan expiró" misma
 */
class EnforceSubscription
{
    /** Patrones de ruta que SIEMPRE están permitidos sin sub activa. */
    private const ALLOWED_PATTERNS = [
        'logout',
        'login',
        'password.*',
        'profile.*',
        'subscription.expired',
        'system_management.tenants.subscriptions.*',  // super renueva
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Feature flag — off por default. Cuando lo activás desde Settings,
        // el middleware empieza a bloquear tenants sin sub. Esto permite tener
        // el código en producción sin habilitarlo hasta que el billing esté listo.
        if (!\App\Models\Setting::getBool('features.subscription_enforcement_enabled', false)) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user) return $next($request);

        // super nunca queda bloqueado.
        if ($user->hasRole('super')) return $next($request);

        // Users sin tenant (system_user de API, super sin tenant) → bypass.
        if (!$user->tenant_id) return $next($request);

        // Rutas exentas — sin esto el usuario no podría salir del sistema.
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if ($request->routeIs($pattern)) return $next($request);
        }

        // Eager load la sub activa del tenant del user.
        $tenant = $user->tenant()->with('activeSubscription')->first();
        if (!$tenant) return $next($request);  // tenant del user no existe → fallthrough

        // A2: solo se bloquea al tenant SUSPENDIDO. "Sin suscripción" = free,
        // y free es usable — no se lockea.
        if (! $tenant->isSuspended()) return $next($request);

        // Bloqueado. Para JSON/API → 403 estructurado; para web → página HTML.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('subscriptions.expired_warning'),
                'subscription_status' => 'expired',
            ], 403);
        }

        return response()->view('subscription-expired', [
            'tenantName'   => $tenant->name,
            'supportEmail' => \App\Models\Setting::get('app.support_email'),
        ], 403);
    }
}
