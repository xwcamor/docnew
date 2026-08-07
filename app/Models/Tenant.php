<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\HasFavorites;
use App\Traits\HasDependents;

class Tenant extends Model
{
    use HasFactory, SoftDeletes, Auditable, HasFavorites, HasDependents;

    protected string $auditModule = 'tenants';

    protected $fillable = [
        'name',
        'logo',
        'address',
        'report_disclaimer',
        'report_approver',
        'report_approver_user_id',
        'require_report_approval',
        'notify_approval_by_email',
        'is_active',
        'timezone',
        'system_user_id',
        'created_by',
        'deleted_by',
        'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'require_report_approval' => 'boolean',
        'notify_approval_by_email' => 'boolean',
    ];

    // Accessor: URL completa del logo con cache-busting.
    // El `?v={updated_at}` evita que el browser muestre el logo viejo
    // cuando el tenant cambia su logo.
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) return null;
        if (Str::startsWith($this->logo, ['http://', 'https://'])) return $this->logo;

        $ts = $this->updated_at?->timestamp ?? time();
        return asset('storage/' . $this->logo) . '?v=' . $ts;
    }

    /**
     * users.tenant_id es nullable (supers sin tenant), pero borrar un tenant
     * con users vivos deja huérfanos. Warn-only, no bloqueante.
     */
    public function dependents(): array
    {
        return [
            'users' => [
                'model' => \App\Models\User::class,
                'fk'    => 'tenant_id',
                'label' => 'users',
                'block' => false,
            ],
        ];
    }

    /**
     * Devuelve true si el plan vigente del tenant habilita la feature.
     * Lee de la tabla `plans` (editable desde UI). Fallback a config si la
     * tabla no existe todavía (edge cases de migration order).
     */
    public function canUseFeature(string $feature): bool
    {
        $plan = Plan::findBySlug($this->currentPlan());
        if ($plan) {
            return $plan->hasFeature($feature);
        }

        // Fallback config/features.php.
        $config = config("features.features.{$feature}", null);
        if ($config === null) return true;
        if (!is_array($config) || empty($config)) return false;
        return in_array($this->currentPlan(), $config, true);
    }

    // ─── Subscriptions ────────────────────────────────────────────────────

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderByDesc('starts_at');
    }

    /**
     * Sub activa (trial o paid). Si hay varias current (no debería pasar pero
     * por seguridad), devolvemos la que termina más tarde. Null si no hay plan.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->current()->latestOfMany('ends_at');
    }

    /**
     * Plan vigente — derivado 100% de la suscripción vigente.
     * No existe la columna `tenants.plan`: la única fuente de verdad es
     * `subscriptions`. Sin suscripción vigente → `free` (el piso).
     *
     * `activeSubscription` se cachea en la instancia tras el primer acceso,
     * así que múltiples llamadas en el mismo request no hacen N+1.
     */
    public function currentPlan(): string
    {
        return $this->activeSubscription?->plan ?: 'free';
    }

    public function isOnTrial(): bool
    {
        return (bool) $this->activeSubscription?->isTrial();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription !== null;
    }

    /**
     * Suspendido = corte deliberado (pago en disputa, fraude). Distinto de
     * "sin suscripción" (eso es `free`, sigue usable). Un tenant está
     * suspendido si NO tiene suscripción vigente y su última suscripción
     * quedó en estado `suspended`. Lo usa EnforceSubscription para bloquear.
     */
    public function isSuspended(): bool
    {
        if ($this->activeSubscription) {
            return false;
        }
        $latest = $this->subscriptions()->latest('starts_at')->first();
        return $latest?->status === Subscription::STATUS_SUSPENDED;
    }

    public function daysUntilExpiration(): ?int
    {
        return $this->activeSubscription?->daysRemaining();
    }

    // ─── Plan limits ──────────────────────────────────────────────────────

    public function maxUsers(): int
    {
        $plan = Plan::findBySlug($this->currentPlan());
        if ($plan) return $plan->max_users;

        // Fallback config.
        return (int) config("features.limits.max_users.{$this->currentPlan()}", PHP_INT_MAX);
    }

    public function maxRecordsPerModule(): int
    {
        $plan = Plan::findBySlug($this->currentPlan());
        if ($plan) return $plan->max_records_per_module;

        return (int) config("features.limits.max_records_per_module.{$this->currentPlan()}", PHP_INT_MAX);
    }

    /** Users humanos vivos hoy (excluye system_user y soft-deleted). */
    public function activeUserCount(): int
    {
        return User::withoutGlobalScopes()
            ->where('tenant_id', $this->id)
            ->when($this->system_user_id, fn ($q) => $q->where('id', '!=', $this->system_user_id))
            ->whereNull('deleted_at')
            ->count();
    }

    public function canCreateUser(): bool
    {
        return $this->activeUserCount() < $this->maxUsers();
    }

    protected static function booted()
    {
        static::creating(function ($tenant) {
            $attempts = 0;
            do {
                $slug = Str::random(22);
                $attempts++;
            } while ($attempts < 5 && Tenant::withTrashed()->where('slug', $slug)->exists());
            $tenant->slug = $slug;

            // Auto-fill timezone si el caller no lo seteó. Heurística:
            //   1. country del user que crea el workspace
            //   2. UTC fallback
            // El admin puede cambiarlo después desde la UI del workspace.
            if (empty($tenant->timezone)) {
                $creatorId = $tenant->created_by ?? auth()->id();
                if ($creatorId) {
                    $tz = DB::table('users')
                        ->join('countries', 'users.country_id', '=', 'countries.id')
                        ->where('users.id', $creatorId)
                        ->value('countries.timezone');
                    $tenant->timezone = $tz ?: 'UTC';
                } else {
                    $tenant->timezone = 'UTC';
                }
            }
        });

        static::deleted(function ($tenant) {
            if (!$tenant->isForceDeleting()) return;
            \App\Models\UserFavorite::where('favoritable_type', static::class)
                ->where('favoritable_id', $tenant->id)
                ->delete();
            \App\Models\UserRecentView::where('viewable_type', static::class)
                ->where('viewable_id', $tenant->id)
                ->delete();
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    /** withTrashed: si el creator fue soft-deleted, igual mostramos el nombre histórico. */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /**
     * The invisible "system user" used as auth context for API tokens issued
     * to this workspace. Set automatically via TenantSystemUserService.
     */
    public function systemUser()
    {
        return $this->belongsTo(User::class, 'system_user_id');
    }

    /** Firmantes de informes del workspace (slots ordenados, escalable a N). */
    public function reportSigners()
    {
        return $this->hasMany(ReportSigner::class)->orderBy('sort_order');
    }

    /** Aprobador de informes del workspace (usuario del sistema; opcional). */
    public function approverUser()
    {
        return $this->belongsTo(User::class, 'report_approver_user_id');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deleted_at');
    }

    /** Texto traducido del estado — consumido por exports. */
    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    /**
     * Filtros: name (multi-tag, unaccent), is_active, plan (array), date ranges,
     * id range, only_favorites, sort + direction.
     */
    public function scopeFilter(Builder $query, Request|array $filters): Builder
    {
        if (is_array($filters)) {
            $filters = new Request($filters);
        }

        $tbl = 'tenants';

        if ($filters->filled('name')) {
            $names = is_array($filters->name) ? $filters->name : [$filters->name];
            $names = array_filter(array_map('trim', $names), fn ($n) => $n !== '');

            if (count($names) > 0) {
                $isPgsql = DB::getDriverName() === 'pgsql';
                $query->where(function ($q) use ($names, $isPgsql, $tbl) {
                    foreach ($names as $name) {
                        $needle = LikeQuery::contains((string) $name);
                        if ($isPgsql) {
                            $q->orWhereRaw(
                                "unaccent(lower({$tbl}.name)) LIKE unaccent(lower(?))",
                                [$needle]
                            );
                        } else {
                            $q->orWhereRaw("{$tbl}.name LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                });
            }
        }

        if ($filters->filled('is_active')) {
            $query->where("{$tbl}.is_active", filter_var($filters->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($filters->filled('plan')) {
            $plans = is_array($filters->plan) ? $filters->plan : [$filters->plan];
            $plans = array_filter(array_map(fn($p) => strtolower(trim($p)), $plans), fn($p) => $p !== '');
            if (count($plans) > 0) {
                // El plan no es una columna — se deriva de la suscripción
                // vigente. `free` = sin suscripción vigente.
                $wantsFree = in_array('free', $plans, true);
                $paidPlans = array_values(array_filter($plans, fn($p) => $p !== 'free'));

                // Subquery "suscripción vigente" — misma definición que
                // Subscription::scopeCurrent() (trial/active/cancelled + futura).
                $currentSub = function ($sub) use ($tbl) {
                    $sub->select(DB::raw(1))
                        ->from('subscriptions')
                        ->whereColumn('subscriptions.tenant_id', "{$tbl}.id")
                        ->whereIn('subscriptions.status', ['trial', 'active', 'cancelled'])
                        ->where('subscriptions.ends_at', '>', now())
                        ->whereNull('subscriptions.deleted_at');
                };

                $query->where(function ($q) use ($wantsFree, $paidPlans, $currentSub) {
                    if (!empty($paidPlans)) {
                        $q->orWhereExists(function ($sub) use ($currentSub, $paidPlans) {
                            $currentSub($sub);
                            $sub->whereIn('subscriptions.plan', $paidPlans);
                        });
                    }
                    if ($wantsFree) {
                        $q->orWhereNotExists($currentSub);
                    }
                });
            }
        }

        if ($filters->filled('created_from')) {
            $query->where("{$tbl}.created_at", '>=', $filters->created_from . ' 00:00:00');
        }
        if ($filters->filled('created_to')) {
            $query->where("{$tbl}.created_at", '<=', $filters->created_to . ' 23:59:59');
        }
        if ($filters->filled('updated_from')) {
            $query->where("{$tbl}.updated_at", '>=', $filters->updated_from . ' 00:00:00');
        }
        if ($filters->filled('updated_to')) {
            $query->where("{$tbl}.updated_at", '<=', $filters->updated_to . ' 23:59:59');
        }
        if ($filters->filled('id_from')) {
            $query->where("{$tbl}.id", '>=', (int) $filters->id_from);
        }
        if ($filters->filled('id_to')) {
            $query->where("{$tbl}.id", '<=', (int) $filters->id_to);
        }

        $advanced = $filters->input('advanced_where');
        if (is_string($advanced)) {
            $advanced = json_decode($advanced, true) ?: null;
        }
        if (is_array($advanced) && !empty($advanced)) {
            \App\Services\Automations\Support\FilterApplier::apply(
                $query,
                ['where' => $advanced],
                static::filterSchema()
            );
        }

        if ($filters->filled('only_favorites') && filter_var($filters->only_favorites, FILTER_VALIDATE_BOOLEAN)) {
            $userId = auth()->id();
            if ($userId) {
                $query->whereExists(function ($q) use ($userId, $tbl) {
                    $q->select(DB::raw(1))
                      ->from('user_favorites')
                      ->whereColumn('user_favorites.favoritable_id', "{$tbl}.id")
                      ->where('user_favorites.favoritable_type', static::class)
                      ->where('user_favorites.user_id', $userId);
                });
            }
        }

        $sort      = $filters->get('sort', 'id');
        $direction = $filters->get('direction', 'asc');
        // 'plan' no es columna — no se puede ordenar por él en SQL.
        if (in_array($sort, ['id', 'name', 'is_active', 'created_at', 'updated_at', 'deleted_at']) && in_array($direction, ['asc', 'desc'])) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(): array
    {
        return [
            ['key' => 'name',       'label' => __('tenants.name'),      'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',  'label' => __('global.active'),     'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'), 'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'), 'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
