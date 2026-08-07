<?php

namespace App\Http\Controllers\UserPreferences;

use App\Http\Controllers\Controller;
use App\Models\UserFavorite;
use Illuminate\Http\Request;

/**
 * FavoriteController — toggle de favoritos polimórfico.
 *
 * El frontend manda { module: 'regions', id: 42 } para alternar el favorito.
 * Mapeamos 'module' → FQCN del modelo via array allowlist (no aceptamos
 * cualquier clase que mande el cliente, sería un riesgo).
 */
class FavoriteController extends Controller
{
    public function toggle(Request $request)
    {
        $data = $request->validate([
            'module' => 'required|string',
            'id'     => 'required|integer',
        ]);

        // Allowlist polimórfica única en config/polymorphic.php — sin esto
        // un cliente malicioso podría mandar `module=Foo` y manipular cualquier
        // tabla via favoritable_type.
        $type = config("polymorphic.modules.{$data['module']}.model");
        abort_unless($type, 422, 'Module not supported for favorites.');

        $userId = $request->user()->id;

        $existing = UserFavorite::where([
            'user_id'          => $userId,
            'favoritable_type' => $type,
            'favoritable_id'   => $data['id'],
        ])->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['ok' => true, 'favorited' => false]);
        }

        UserFavorite::create([
            'user_id'          => $userId,
            'favoritable_type' => $type,
            'favoritable_id'   => $data['id'],
        ]);

        return response()->json(['ok' => true, 'favorited' => true]);
    }
}
