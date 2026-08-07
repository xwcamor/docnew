<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MaintenanceMode — middleware controlado por Setting `app.maintenance_mode`.
 *
 * Si está activo, devuelve página de mantenimiento (HTTP 503) excepto para:
 *   - super (puede seguir trabajando y desactivar el toggle)
 *   - rutas del propio Settings (para poder apagarlo desde la UI)
 *   - logout (para que un user pueda salir)
 *
 * Diferencia con `php artisan down`: este es runtime (DB-backed) — super
 * lo togglea sin SSH. El built-in de Laravel requiere CLI.
 */
class MaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Setting::getBool('app.maintenance_mode', false)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user && $user->hasRole('super')) {
            return $next($request);
        }

        // Permitir rutas del propio Settings + logout para no quedar atrapado.
        $allowed = ['logout', 'login', 'system_management.settings.*'];
        foreach ($allowed as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => __('global.maintenance_message'),
            ], 503);
        }

        return response()->view('maintenance', [
            'supportEmail' => Setting::get('app.support_email'),
        ], 503);
    }
}
