<?php

namespace App\Models;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use App\Traits\HasFavorites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Company — empresa contratista: la que ejecuta los trabajos y a la que
 * pertenece la gente que firma en obra.
 *
 * Se identifica por su RUC (`num_doc`, único por país dentro del workspace);
 * `name` es el nombre corto con el que se la conoce en obra y `complete_name`
 * la razón social del documento. Es PER-TENANT, por eso usa
 * BelongsToTenantOrGlobal. Mantiene SoftDeletes + Auditable + HasFavorites.
 */
class Company extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\Lockable;

    protected string $auditModule = 'companies';

    protected $fillable = [
        'slug', 'name', 'country_id',
        'num_doc', 'legacy_id', 'is_active', 'complete_name', 'tenant_id',
        'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    /** Vínculos con personas: una persona puede trabajar en varias empresas. */
    public function people()
    {
        return $this->hasMany(PersonCompanyLink::class);
    }

    public function workPlans()
    {
        return $this->hasMany(WorkPlan::class);
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
     * scopeFilter — mismo patrón que Customer, sobre la tabla companies.
     * Soporta name (multi-tag accent-insensitive), code (substring),
     * is_active (bool), rangos de fecha/id, filtros avanzados y favoritos.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'companies';

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

        $query->when($request->filled('num_doc'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.num_doc LIKE ?" : "{$tbl}.num_doc LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->num_doc)]);
        });

        // El nombre corto y la razon social se buscan juntos: en obra se escribe
        // uno u otro indistintamente.
        $query->when($request->filled('complete_name'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "unaccent(lower({$tbl}.complete_name)) LIKE unaccent(lower(?))" : "{$tbl}.complete_name LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->complete_name)]);
        });

        $query->when($request->filled('country_id'), function ($q) use ($request, $tbl) {
            $ids = array_filter((array) $request->input('country_id'), fn ($v) => $v !== '' && $v !== null);
            if (empty($ids)) return;
            $q->whereIn("{$tbl}.country_id", array_map('intval', $ids));
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

        $advanced = $request->input('advanced_where');
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
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }
        if ($sort === 'tenant') {
            // Orden por workspace: nombre vía left join (nulls = global).
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')
                  ->orderBy('tenants.name', $direction);
        } elseif ($sort === 'country') {
            $query->leftJoin('countries', "{$tbl}.country_id", '=', 'countries.id')
                  ->orderBy('countries.name', $direction);
        } elseif (in_array($sort, ['people_count', 'work_plans_count'], true)) {
            // Alias del withCount del controller — sin prefijo de tabla.
            $query->orderBy($sort, $direction);
        } elseif (in_array($sort, ['id', 'name', 'num_doc', 'is_active', 'complete_name', 'created_at', 'updated_at'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(array $opts = []): array
    {
        return [
            ['key' => 'name',          'label' => __('companies.name'),          'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'num_doc',       'label' => __('companies.num_doc'),       'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'complete_name', 'label' => __('companies.complete_name'), 'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'country_id',    'label' => __('companies.country'),       'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['countries'] ?? []],
            ['key' => 'is_active',     'label' => __('companies.is_active'),     'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at',    'label' => __('global.created_at'),       'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at',    'label' => __('global.updated_at'),       'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
