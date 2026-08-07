<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Transformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SearchController — buscador global del topbar. Devuelve coincidencias de
 * transformadores (por serie/TAG/marca/subestación o por ESTADO de salud) y
 * clientes (por nombre) del workspace actual.
 *
 * Seguridad: los modelos ya auto-filtran por tenant (BelongsToTenant) y por
 * clientes asignados; además se respeta el permiso de vista de cada módulo.
 */
class SearchController extends Controller
{
    /** health_rating (0-4) → token de color y clave de condición del semáforo. */
    private const RATING = [
        4 => ['green', 'muy_bueno'], 3 => ['lime', 'bueno'], 2 => ['yellow', 'medio'],
        1 => ['orange', 'malo'], 0 => ['red', 'muy_malo'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['transformers' => [], 'customers' => []]);
        }

        $user = $request->user();
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = '%' . $q . '%';

        $transformers = [];
        if ($user->can('transformers.view')) {
            // Texto: serie, TAG, marca o subestación.
            $matches = Transformer::query()
                ->where(fn ($w) => $w
                    ->where('serial', $like, $term)
                    ->orWhere('tag', $like, $term)
                    ->orWhereHas('brand', fn ($b) => $b->where('name', $like, $term))
                    ->orWhereHas('substation', fn ($s) => $s->where('name', $like, $term)))
                ->with('customer:id,name', 'brand:id,name')
                ->orderBy('serial')
                ->limit(6)
                ->get();

            // Estado de salud: si la query es una condición ("malo", "crítico"…),
            // sumar los trafos en ese estado (peores primero).
            if (($rating = $this->matchRating($q)) !== null) {
                $byState = Transformer::query()
                    ->where('health_rating', $rating)
                    ->with('customer:id,name', 'brand:id,name')
                    ->orderBy('health_index')
                    ->limit(6)
                    ->get();
                $matches = $matches->concat($byState)->unique('id')->take(8);
            }

            $transformers = $matches->map(function ($t) {
                [$color, $key] = self::RATING[$t->health_rating] ?? [null, null];
                return [
                    'slug'      => $t->slug,
                    'serial'    => $t->serial,
                    'tag'       => $t->tag,
                    'customer'  => $t->customer?->name,
                    'brand'     => $t->brand?->name,
                    'condition' => $key ? \App\Support\Diagnostics\ConditionLabel::forKey($key) : null,
                    'color'     => $color,
                ];
            })->values()->all();
        }

        $customers = [];
        if ($user->can('customers.view')) {
            $customers = Customer::query()
                ->where('name', $like, $term)
                ->orderBy('name')
                ->limit(6)
                ->get(['id', 'slug', 'name'])
                ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name])
                ->all();
        }

        return response()->json(compact('transformers', 'customers'));
    }

    /**
     * Si la query coincide con una condición del semáforo (etiqueta i18n del
     * locale actual o el nombre canónico es/en), devuelve su health_rating (0-4).
     */
    private function matchRating(string $q): ?int
    {
        $s = mb_strtolower(trim($q));
        if (mb_strlen($s) < 3) {
            return null;
        }
        // Nombres canónicos (es) + etiquetas i18n del locale actual.
        $canon = [4 => 'muy bueno', 3 => 'bueno', 2 => 'medio', 1 => 'malo', 0 => 'muy malo'];
        $keys  = [4 => 'muy_bueno', 3 => 'bueno', 2 => 'medio', 1 => 'malo', 0 => 'muy_malo'];
        foreach ($keys as $rating => $key) {
            $label = mb_strtolower(\App\Support\Diagnostics\ConditionLabel::forKey($key));
            if (str_contains($canon[$rating], $s) || str_contains($label, $s)) {
                return $rating;
            }
        }
        return null;
    }
}
