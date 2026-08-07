<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforcePlanFeature — bloquea acceso a una feature si el plan del tenant
 * del user no la desbloquea.
 *
 * Uso en routes:
 *   Route::middleware('plan_feature:api_access')->group(function () { ... });
 *
 * Reglas:
 *   - super: bypass (puede usar todo siempre)
 *   - User sin tenant (system_user para API): bypass — el user de un token API
 *     ya pasó por el gate cuando se creó el token (TenantSubscriptionController)
 *   - Tenant cuyo plan NO desbloquea la feature: 402 Payment Required con
 *     mensaje claro + link a planes
 *
 * Las features están definidas en config/features.php → map feature → planes.
 */
class EnforcePlanFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();

        if (!$user) return $next($request);
        if ($user->hasRole('super')) return $next($request);

        $tenant = $user->tenant;
        if (!$tenant) return $next($request);  // user sin tenant

        if ($tenant->canUseFeature($feature)) {
            return $next($request);
        }

        // Feature bloqueada por plan.
        $message = __('plans.feature_locked');

        if ($request->expectsJson()) {
            return response()->json([
                'message'         => $message,
                'feature'         => $feature,
                'current_plan'    => $tenant->currentPlan(),
                'required_plans'  => config("features.features.{$feature}", []),
            ], 402);
        }

        return back()->with('error', $message);
    }
}
