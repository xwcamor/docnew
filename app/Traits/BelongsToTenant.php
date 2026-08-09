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
                // El workspace efectivo, no su columna: si ha ENTRADO en uno, lo
                // que crea es de ese workspace y no un huerfano sin dueño.
                if (empty($model->tenant_id)) {
                    $model->tenant_id = \App\Support\TenantContext::actual($user);
                }
                return;
            }

            // Non-super: SIEMPRE forzar al tenant del actor, ignorando cualquier
            // tenant_id mass-assigned. Bloquea cross-tenant writes incluso si un
            // FormRequest futuro deja pasar `tenant_id` por error.
            $model->tenant_id = $user->tenant_id;
        });

        // ── Nada de huerfanos ────────────────────────────────────────────────
        // En un modelo per-tenant, `tenant_id` null no significa "de todos":
        // significa DE NADIE. Ese registro no lo ve ningun admin y solo aparece
        // para el super — asi nacieron las empresas huerfanas que hubo que
        // rescatar con una migracion. Si el super esta en la consola y crea algo
        // de estos, se le dice que entre antes en un workspace.
        //
        // `User` es la excepcion legitima: un usuario sin workspace es un super,
        // y crearlos es justo lo que se hace desde la consola. Por eso el guard
        // se puede desactivar con `$requiereWorkspace = false`.
        static::creating(function (Model $model) {
            if (! auth()->hasUser() || $model->tenant_id !== null) {
                return;
            }
            if (property_exists($model, 'requiereWorkspace') && $model->requiereWorkspace === false) {
                return;
            }

            throw new \DomainException(__('tenants.enter_first'));
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

            // El super ve todos los workspaces SOLO desde la consola. Si ha
            // entrado en uno, se comporta como su admin: ve ese y nada mas.
            $tenantId = \App\Support\TenantContext::actual($user);

            if ($tenantId === null && method_exists($user, 'hasRole') && $user->hasRole('super')) {
                return;
            }

            // Apply tenant filter using the model's table to avoid ambiguity in joins.
            $table = $builder->getModel()->getTable();
            $builder->where("{$table}.tenant_id", $tenantId);
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
