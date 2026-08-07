<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\HasFavorites;
use App\Traits\HasDependents;

class Country extends Model
{
    use HasFactory, SoftDeletes, Auditable, HasFavorites, HasDependents;

    protected string $auditModule = 'countries';

    protected $fillable = [
        'name',
        'iso_code',
        'currency',
        'timezone',
        'region_id',
        'default_locale_id',
        'is_active',
        'created_by',
        'deleted_by',
        'deleted_description',
    ];

    /** is_active a bool real: SQLite devuelve 0/1, Postgres true/false. */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Modelos con FK apuntando aquí. block=true bloquea el delete; false solo
     * muestra warning. users.country_id bloquea porque users vive ahí; el resto
     * son advertencias o cascades.
     */
    public function dependents(): array
    {
        return [
            'users' => [
                'model' => \App\Models\User::class,
                'fk'    => 'country_id',
                'label' => 'users',
                'block' => true,
            ],
        ];
    }

    public function region()
    {
        return $this->belongsTo(Region::class);
    }

    public function defaultLocale()
    {
        return $this->belongsTo(Locale::class, 'default_locale_id');
    }

    protected static function booted()
    {
        static::creating(function ($country) {
            // El UNIQUE INDEX en slug es la red de seguridad real ante colisiones
            // (probabilidad 1/62^22, casi imposible). El check optimista evita el
            // INSERT fallido en el 99.99999% de casos.
            $attempts = 0;
            do {
                $slug = Str::random(22);
                $attempts++;
            } while ($attempts < 5 && Country::withTrashed()->where('slug', $slug)->exists());

            $country->slug = $slug;
        });

        // Solo hard-delete limpia favoritos/recents asociados — soft-delete los
        // preserva (el usuario podría restaurar y querer mantener su favorito).
        static::deleted(function ($country) {
            if (!$country->isForceDeleting()) return;
            \App\Models\UserFavorite::where('favoritable_type', static::class)
                ->where('favoritable_id', $country->id)
                ->delete();
            \App\Models\UserRecentView::where('viewable_type', static::class)
                ->where('viewable_id', $country->id)
                ->delete();
        });
    }

    // Use slug for route model binding
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

    /** Texto traducido del estado — consumido por exports (CSV/Excel/PDF/Word). */
    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    /**
     * Filtros soportados: name (string|array OR'd), is_active, created/updated_from/to,
     * id_from/id_to, only_favorites (per-user), sort + direction.
     * Postgres: búsqueda por name es accent + case insensitive (extensión unaccent).
     */
    public function scopeFilter(Builder $query, Request|array $filters): Builder
    {
        if (is_array($filters)) {
            $filters = new Request($filters);
        }

        // Columnas con prefijo `countries.*` para evitar ambigüedad cuando otros
        // scopes (ej. orderByFavoriteFirst) hacen JOIN al pivot user_favorites.
        $tbl = 'countries';

        if ($filters->filled('name')) {
            $names = is_array($filters->name) ? $filters->name : [$filters->name];
            $names = array_filter(array_map('trim', $names), fn($n) => $n !== '');

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

        if ($filters->filled('region_id')) {
            $ids = is_array($filters->region_id) ? $filters->region_id : [$filters->region_id];
            $ids = array_filter(array_map('intval', $ids));
            if (count($ids) > 0) {
                $query->whereIn("{$tbl}.region_id", $ids);
            }
        }

        if ($filters->filled('default_locale_id')) {
            $ids = is_array($filters->default_locale_id) ? $filters->default_locale_id : [$filters->default_locale_id];
            $ids = array_filter(array_map('intval', $ids));
            if (count($ids) > 0) {
                $query->whereIn("{$tbl}.default_locale_id", $ids);
            }
        }

        if ($filters->filled('iso_code')) {
            $codes = is_array($filters->iso_code) ? $filters->iso_code : [$filters->iso_code];
            $codes = array_filter(array_map(fn($c) => strtoupper(trim($c)), $codes));
            if (count($codes) > 0) {
                $query->whereIn(DB::raw("UPPER({$tbl}.iso_code)"), $codes);
            }
        }

        if ($filters->filled('currency')) {
            $cur = is_array($filters->currency) ? $filters->currency : [$filters->currency];
            $cur = array_filter(array_map(fn($c) => strtoupper(trim($c)), $cur));
            if (count($cur) > 0) {
                $query->whereIn(DB::raw("UPPER({$tbl}.currency)"), $cur);
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
        // `deleted_at` solo aplica cuando el caller hizo `onlyTrashed()` antes
        // (vista de Trash). En el listado normal el WHERE deleted_at IS NULL
        // hace que cualquier sort por deleted_at sea no-op pero seguro.
        if ($sort === 'region' && in_array($direction, ['asc', 'desc'])) {
            // Orden por nombre de región (la columna muestra el nombre, no el id)
            // vía left join. El select explícito de countries.* evita colisión.
            $query->leftJoin('regions', 'regions.id', '=', "{$tbl}.region_id")
                  ->orderBy('regions.name', $direction);
        } elseif (in_array($sort, ['id', 'name', 'iso_code', 'currency', 'timezone', 'is_active', 'region_id', 'default_locale_id', 'created_at', 'updated_at', 'deleted_at']) && in_array($direction, ['asc', 'desc'])) {
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
            ['key' => 'name',       'label' => __('countries.name'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'iso_code',   'label' => __('countries.iso_code'), 'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',  'label' => __('global.active'),      'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),  'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),  'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}