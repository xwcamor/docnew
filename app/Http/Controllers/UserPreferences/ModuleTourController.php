<?php

namespace App\Http\Controllers\UserPreferences;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ModuleTourController — endpoint para marcar onboarding tours como completados.
 *
 * El frontend dispara esto cuando el usuario:
 *   - Termina los pasos del tour de un módulo
 *   - Cierra/skipea el tour (también lo marcamos completado para no insistir)
 *
 * Persistimos en `users.module_tours` (JSON), key = slug del módulo, value =
 * timestamp ISO. Si el módulo ya está marcado, este endpoint no re-escribe
 * (idempotente). El frontend chequea el shared prop `auth.user.module_tours`
 * para decidir si mostrar el tour.
 */
class ModuleTourController extends Controller
{
    public function complete(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string|alpha_dash|max:60',
        ]);

        $user = $request->user();
        if (!$user) abort(401);

        $tours = $user->module_tours ?? [];
        if (!isset($tours[$data['module']])) {
            $tours[$data['module']] = Carbon::now()->toIso8601String();
            $user->module_tours = $tours;
            // saveQuietly: estado UX privado, no debe ensuciar audit_logs.
            $user->saveQuietly();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Resetea TODOS los tours del usuario. Útil para "Volver a verlos" desde
     * la página de perfil. Después del reset, cada módulo va a disparar su
     * propio tour la próxima vez que el usuario entre.
     */
    public function reset(Request $request)
    {
        $user = $request->user();
        if (!$user) abort(401);

        $user->module_tours = null;
        $user->saveQuietly();

        return response()->json(['ok' => true]);
    }
}
