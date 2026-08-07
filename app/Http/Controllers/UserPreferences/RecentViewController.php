<?php

namespace App\Http\Controllers\UserPreferences;

use App\Http\Controllers\Controller;
use App\Models\UserRecentView;
use Illuminate\Http\Request;

/**
 * RecentViewController — track polimórfico de "últimos vistos".
 *
 * Hasta ahora trackeábamos solo en la página Show de cada módulo. Pero la
 * UX real usa drawers (preview rápido) — entonces el usuario "ve" muchas
 * regiones sin nunca tocar /regions/{slug}. Este endpoint permite trackear
 * desde cualquier UI (drawer open, hover preview, etc.).
 *
 * Misma allowlist que FavoriteController para no aceptar cualquier modelo.
 */
class RecentViewController extends Controller
{
    public function track(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string',
            'id'     => 'required|integer',
        ]);

        // Allowlist polimórfica en config/polymorphic.php (single source of truth).
        $type = config("polymorphic.modules.{$data['module']}.model");
        abort_unless($type, 422, 'Module not supported for recent views.');

        $userId = $request->user()->id;
        UserRecentView::track($userId, $type, (int) $data['id']);

        return response()->json(['ok' => true]);
    }
}
