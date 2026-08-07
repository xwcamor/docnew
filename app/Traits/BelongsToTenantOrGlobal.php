<?php

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToTenantOrGlobal — variante de BelongsToTenant para CATÁLOGOS con base
 * global compartida + overrides por tenant (mismo espíritu que las reglas de
 * diagnóstico).
 *
 * Diferencia clave vs BelongsToTenant:
 *   - BelongsToTenant: scope estricto `tenant_id = X`. Un registro NULL no lo ve
 *     nadie (huérfano). Correcto para Customer/Transformer (datos privados).
 *   - BelongsToTenantOrGlobal: scope `tenant_id = X OR tenant_id IS NULL`. Un
 *     registro con tenant_id NULL es GLOBAL → lo ven TODOS los workspaces; cada
 *     tenant además ve (y gestiona) los suyos. Para catálogos: marcas, labs,
 *     conmutador, etc.
 *
 * Reglas de escritura:
 *   - super (sin tenant): crea/edita GLOBALES (tenant_id NULL). Ve todo.
 *   - admin de un tenant: crea registros propios (tenant_id forzado al suyo) y
 *     NO puede editar/borrar un global (es el estándar compartido). Sí ve los
 *     globales en sus selectores.
 *
 * Requiere columna `tenant_id` nullable en la tabla.
 */
trait BelongsToTenantOrGlobal
{
    public static function bootBelongsToTenantOrGlobal(): void
    {
        // ── Auto-fill tenant_id al crear ────────────────────────────────────
        static::creating(function (Model $model) {
            if (! auth()->hasUser()) {
                return;
            }
            $user    = auth()->user();
            $isSuper = method_exists($user, 'hasRole') && $user->hasRole('super');

            if ($isSuper) {
                // Super: su tenant_id es NULL → el registro nace GLOBAL.
                // (Si explícitamente trae un tenant_id, se respeta.)
                if (empty($model->tenant_id)) {
                    $model->tenant_id = $user->tenant_id; // null = global
                }
                return;
            }

            // No-super: SIEMPRE su propio tenant (bloquea cross-tenant write).
            $model->tenant_id = $user->tenant_id;
        });

        // ── Protección: un no-super no modifica/borra un GLOBAL ──────────────
        $guardGlobal = function (Model $model) {
            if (! auth()->hasUser()) {
                return;
            }
            $user = auth()->user();
            if (method_exists($user, 'hasRole') && $user->hasRole('super')) {
                return;
            }
            // tenant_id ORIGINAL del registro (no el que se intenta asignar).
            if ($model->getOriginal('tenant_id') === null) {
                throw new AuthorizationException(
                    'Este registro es global del catálogo; solo un super puede modificarlo.'
                );
            }
        };
        static::updating($guardGlobal);
        static::deleting($guardGlobal);

        // ── Scope: propios + globales ───────────────────────────────────────
        static::addGlobalScope('tenantOrGlobal', function (Builder $builder) {
            if (! auth()->hasUser()) {
                return;
            }
            $user = auth()->user();
            if (method_exists($user, 'hasRole') && $user->hasRole('super')) {
                return; // super ve todo
            }
            $table = $builder->getModel()->getTable();
            $builder->where(function ($q) use ($table, $user) {
                $q->where("{$table}.tenant_id", $user->tenant_id)
                    ->orWhereNull("{$table}.tenant_id");
            });
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
