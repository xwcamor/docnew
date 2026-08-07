<?php

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToTenant — drop-in trait that scopes a model to the current user's tenant.
 *
 * Usage on a tenant-scoped model:
 *   class Patient extends Model {
 *       use HasFactory, SoftDeletes, BelongsToTenant;
 *   }
 *
 * Behavior:
 *   - On `creating`: auto-fills tenant_id from auth()->user()->tenant_id (if not already set).
 *   - Adds a global scope: every query filters by the current user's tenant_id.
 *
 * Bypass conditions (no scope applied):
 *   - No authenticated user (e.g. login form, console commands, queue workers).
 *   - User has the super role (sees ALL tenants for support/admin).
 *
 * IMPORTANT — only apply this to models that have a `tenant_id` column AND are
 * meant to be per-tenant. Global tables (countries, regions, languages, etc.)
 * MUST NOT use this trait — they'd break since they have no tenant_id.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // ── Auto-fill tenant_id when creating a record ──────────────────────
        // hasUser() checks the cached guard user without triggering a query —
        // crucial because triggering one here would recurse into the User
        // model's own global scopes during auth resolution.
        //
        // Defense-in-depth contra cross-tenant IDOR: si el actor NO es super,
        // forzamos su propio tenant_id incluso cuando el modelo viene con uno
        // distinto (típicamente por mass-assignment desde un request). Super
        // mantiene la libertad de crear entidades en cualquier tenant — caso
        // legítimo, ej. TenantService al crear el admin del workspace nuevo.
        static::creating(function (Model $model) {
            if (! auth()->hasUser()) {
                return;
            }
            $user    = auth()->user();
            $isSuper = method_exists($user, 'hasRole') && $user->hasRole('super');

            if ($isSuper) {
                // Super: solo autorellena si vino vacío (preserva override explícito).
                if (empty($model->tenant_id)) {
                    $model->tenant_id = $user->tenant_id;
                }
                return;
            }

            // Non-super: SIEMPRE forzar al tenant del actor, ignorando cualquier
            // tenant_id mass-assigned. Bloquea cross-tenant writes incluso si un
            // FormRequest futuro deja pasar `tenant_id` por error.
            $model->tenant_id = $user->tenant_id;
        });

        // ── Global scope: tenant isolation ──────────────────────────────────
        static::addGlobalScope('tenant', function (Builder $builder) {
            // Skip during auth resolution (hasUser=false) and unauthenticated
            // contexts (console, queues, login form). Once the user is fully
            // loaded into the guard, hasUser() flips to true and scope applies.
            if (! auth()->hasUser()) {
                return;
            }

            $user = auth()->user();

            // super sees all tenants — bypass scope.
            if (method_exists($user, 'hasRole') && $user->hasRole('super')) {
                return;
            }

            // Apply tenant filter using the model's table to avoid ambiguity in joins.
            $table = $builder->getModel()->getTable();
            $builder->where("{$table}.tenant_id", $user->tenant_id);
        });
    }

    /**
     * Relationship: tenant the record belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
