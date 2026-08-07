<?php

namespace App\Models;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use App\Traits\HasFavorites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Customer — master template del scaffold `php artisan make:module`.
 *
 * Patron base per-tenant: SoftDeletes + Auditable + BelongsToTenantOrGlobal + HasFavorites.
 * Las columnas custom del dominio se agregan a este modelo y a la migration.
 */
class Customer extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\RestrictedToAssignedCustomers, \App\Traits\Lockable;

    /** El cliente se referencia a sí mismo por su id (no customer_id). */
    public function customerScopeColumn(): string
    {
        return 'id';
    }

    protected string $auditModule = 'customers';

    protected $fillable = [
        'slug', 'name', 'cod', 'address', 'logo', 'country_id', 'is_active', 'tenant_id',
        'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** URL completa del logo con cache-busting (mismo patrón que Tenant). */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }
        if (\Illuminate\Support\Str::startsWith($this->logo, ['http://', 'https://'])) {
            return $this->logo;
        }
        return asset('storage/' . $this->logo) . '?v=' . ($this->updated_at?->timestamp ?? time());
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** Ubicaciones del cliente (nivel 1 de su jerarquía geográfica). */
    public function locations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerLocation::class)->orderBy('name');
    }

    /** Áreas del cliente (a través de sus ubicaciones) — para conteos. */
    public function areas(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(CustomerArea::class, CustomerLocation::class);
    }

    /** Transformadores del cliente (FK directa customer_id) — para conteos. */
    public function transformers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Transformer::class);
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                do {
                    $slug = Str::random(22);
                } while (static::withTrashed()->where('slug', $slug)->exists());
                $model->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /** Texto traducido del estado — consumido por exports (CSV/Excel/PDF/Word). */
    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    /**
     * scopeFilter — aplica los filtros del request al query.
     *
     * Soporta name (multi-tag con accent insensitive), cod (substring),
     * country_id (multiselect), is_active (bool), created_at range,
     * id range. Igual patron que Region/Country/etc.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        // Columnas con prefijo `customers.*` para evitar ambiguedad cuando
        // otros scopes (ej. orderByFavoriteFirst) hacen JOIN.
        $tbl = 'customers';

        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $names = is_array($request->name) ? $request->name : [$request->name];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (empty($names)) return;
            $q->where(function ($qq) use ($names, $isPgsql, $tbl) {
                foreach ($names as $name) {
                    $needle = LikeQuery::contains((string) $name);
                    if ($isPgsql) {
                        $qq->orWhereRaw("unaccent(lower({$tbl}.name)) LIKE unaccent(lower(?))", [$needle]);
                    } else {
                        $qq->orWhereRaw("{$tbl}.name LIKE ? ESCAPE '\\'", [$needle]);
                    }
                }
            });
        });

        $query->when($request->filled('cod'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.cod LIKE ?" : "{$tbl}.cod LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->cod)]);
        });

        $query->when($request->filled('country_id'), function ($q) use ($request, $tbl) {
            $ids = is_array($request->country_id) ? $request->country_id : [$request->country_id];
            $ids = array_filter($ids);
            if (!empty($ids)) $q->whereIn("{$tbl}.country_id", $ids);
        });

        // Chips rápidos: activos / inactivos / con trafos / sin trafos.
        $query->when($request->filled('customer_group'), function ($q) use ($request, $tbl) {
            match ($request->customer_group) {
                'active'     => $q->where("{$tbl}.is_active", true),
                'inactive'   => $q->where("{$tbl}.is_active", false),
                'with_tx'    => $q->whereHas('transformers'),
                'without_tx' => $q->whereDoesntHave('transformers'),
                default      => null,
            };
        });

        $query->when($request->filled('is_active'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.is_active", filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });

        $query->when($request->filled('created_from'), fn ($q) => $q->where("{$tbl}.created_at", '>=', $request->created_from . ' 00:00:00'));
        $query->when($request->filled('created_to'),   fn ($q) => $q->where("{$tbl}.created_at", '<=', $request->created_to . ' 23:59:59'));
        $query->when($request->filled('updated_from'), fn ($q) => $q->where("{$tbl}.updated_at", '>=', $request->updated_from . ' 00:00:00'));
        $query->when($request->filled('updated_to'),   fn ($q) => $q->where("{$tbl}.updated_at", '<=', $request->updated_to . ' 23:59:59'));
        $query->when($request->filled('id_from'), fn ($q) => $q->where("{$tbl}.id", '>=', (int) $request->id_from));
        $query->when($request->filled('id_to'),   fn ($q) => $q->where("{$tbl}.id", '<=', (int) $request->id_to));

        // Filtros avanzados (drawer): array de clausulas {field, op, value}
        // que el frontend manda como JSON en `advanced_where`. Cada clausula
        // se valida contra el schema declarado en filterSchema() y se aplica
        // con coercion tipada via FilterApplier (reutilizado de Automations).
        $advanced = $request->input('advanced_where');
        if (is_string($advanced)) {
            $advanced = json_decode($advanced, true) ?: null;
        }
        if (is_array($advanced) && !empty($advanced)) {
            // Lista PLANA de cláusulas; cada una (menos la 1ª) lleva su conector
            // 'conj' ('and'|'or') — el FilterApplier los une con esa precedencia.
            \App\Services\Automations\Support\FilterApplier::apply(
                $query,
                ['where' => $advanced],
                static::filterSchema()
            );
        }

        if ($request->filled('only_favorites') && filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN)) {
            $userId = auth()->id();
            if ($userId) {
                $query->whereExists(function ($q) use ($userId, $tbl) {
                    $q->select(\DB::raw(1))
                      ->from('user_favorites')
                      ->whereColumn('user_favorites.favoritable_id', "{$tbl}.id")
                      ->where('user_favorites.favoritable_type', static::class)
                      ->where('user_favorites.user_id', $userId);
                });
            }
        }

        $sort = $request->get('sort', 'id');
        $direction = $request->get('direction', 'desc');
        if (in_array($sort, ['id', 'name', 'cod', 'is_active', 'created_at', 'updated_at']) && in_array($direction, ['asc', 'desc'])) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        } elseif ($sort === 'tenant' && in_array($direction, ['asc', 'desc'])) {
            // Orden por workspace: nombre vía left join (el select customers.*
            // del controller evita colisión de columnas). Nulls = global.
            $query->leftJoin('tenants', 'tenants.id', '=', "{$tbl}.tenant_id")
                  ->orderBy('tenants.name', $direction);
        } elseif ($sort === 'country' && in_array($direction, ['asc', 'desc'])) {
            // Orden por país: nombre vía left join (mismo patrón que workspace; el
            // select customers.* del controller evita colisión de columnas).
            $query->leftJoin('countries', 'countries.id', '=', "{$tbl}.country_id")
                  ->orderBy('countries.name', $direction);
        } elseif (in_array($sort, ['locations_count', 'areas_count', 'substations_count', 'transformers_count'], true) && in_array($direction, ['asc', 'desc'])) {
            // Orden por conteos de la jerarquía: los alias ya vienen en el SELECT
            // del controller (withCount + subquery escalar). Son alias, sin prefijo
            // de tabla.
            $query->orderBy($sort, $direction);
        }

        return $query;
    }

    /**
     * Schema de filtros avanzados — declara qué columnas el drawer "Filtros
     * avanzados" puede mostrar como condiciones. Cada field expone:
     *   - key       : columna real de la BD (prefijada con `customers.`)
     *   - label     : texto i18n para el dropdown del field
     *   - type      : string | number | boolean | date | enum (el frontend
     *                 renderiza el control de "valor" según este type)
     *   - operators : subset de operadores que aplican
     *   - options   : (solo enum) opciones del dropdown
     *
     * Reusa el mismo shape que automations.DataSourceContract — el frontend
     * puede compartir el componente AdvancedFilterDrawer entre módulos.
     *
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(array $opts = []): array
    {
        return [
            ['key' => 'name',       'label' => __('customers.name'),       'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'cod',        'label' => __('customers.cod'),        'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'country_id', 'label' => __('customers.country'),    'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['countries'] ?? []],
            ['key' => 'is_active',  'label' => __('customers.is_active'),  'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),    'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),    'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
