<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use App\Models\Role;

/**
 * HideSuperScope — oculta usuarios internos del listado.
 *
 * Aplica dos filtros automaticos a las queries de User:
 *
 *  1. Oculta a los super para viewers no-super. El creador del
 *     sistema (Carlos) es invisible para admins de tenants y sus workers.
 *
 *  2. Oculta a los system_users con rol `api`. Esos son cuentas internas
 *     duenas de los tokens API de Sanctum, no usuarios reales. Tienen email
 *     tipo `api+xxx@system.local` y no deben aparecer en los listados de
 *     usuarios del workspace (los admins solo deben ver personas).
 *
 * Bypass: si el viewer ES super, ve TODO (incluidos otros super
 * y los system_users de cada tenant). Contexto sin auth tambien bypassa
 * (necesario para Auth::attempt durante login).
 */
class HideSuperScope implements Scope
{
    private static ?int $superRoleId   = null;
    private static ?int $apiRoleId     = null;
    private static bool $rolesResolved = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->hasUser()) {
            return;
        }

        $viewer = auth()->user();

        // super ve todo, incluso otros super y los system_users.
        if (method_exists($viewer, 'hasRole') && $viewer->hasRole('super')) {
            return;
        }

        // Resolver + cachear los role ids una sola vez por request.
        if (! self::$rolesResolved) {
            self::$superRoleId   = Role::where('name', 'super')->where('guard_name', 'web')->value('id');
            self::$apiRoleId     = Role::where('name', 'api')->where('guard_name', 'web')->value('id');
            self::$rolesResolved = true;
        }

        $hideIds = array_filter([self::$superRoleId, self::$apiRoleId]);
        if (empty($hideIds)) {
            return; // roles aun no seedeados — nada que ocultar
        }

        $builder->whereDoesntHave('roles', function ($q) use ($hideIds) {
            $q->whereIn('roles.id', $hideIds);
        });
    }

    /**
     * Resetea la caché estática de role ids. En producción no hace falta (cada
     * request es un proceso nuevo), pero en tests muchos requests comparten el
     * proceso PHP y RefreshDatabase recrea los roles con IDs distintos por test;
     * sin esto, el scope filtraría por el id viejo (rol equivocado). Lo llama
     * TestCase::setUp(), igual que Plan::flushCache().
     */
    public static function flushCache(): void
    {
        self::$superRoleId   = null;
        self::$apiRoleId     = null;
        self::$rolesResolved = false;
    }
}
