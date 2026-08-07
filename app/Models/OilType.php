<?php

namespace App\Models;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\HasFavorites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * OilType — catálogo de tipos de aceite (Mineral, Silicona, Vegetal…).
 *
 * Es a la vez:
 *   - Eje de diagnóstico del motor (las reglas cambian según el aceite) → ruleSets().
 *   - Módulo de negocio CRUD estilo Customer, pero como CATÁLOGO GLOBAL
 *     (sin tenant): todos los workspaces ven el mismo catálogo. Por eso NO
 *     usa BelongsToTenant. Mantiene SoftDeletes + Auditable + HasFavorites.
 *
 * Campos de dominio extra respecto al scaffold: `code` (slug técnico) y
 * `sort_order`. El módulo generado opera sobre `name` + `is_active`.
 */
class OilType extends Model
{
    use HasFactory, SoftDeletes, Auditable, HasFavorites;

    protected string $auditModule = 'oil_types';

    protected $fillable = [
        'slug', 'name', 'code', 'is_active', 'sort_order',
        'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Reglas de diagnóstico que dependen de este tipo de aceite. */
    public function ruleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class);
    }

    /**
     * Nombre para mostrar en la UI: traducido por código (oil_types.names.<code>)
     * siguiendo el idioma del request. Si no hay traducción (aceite custom creado
     * por el usuario), cae al `name` de la BD. Las reglas siguen atadas al code.
     */
    public function getDisplayNameAttribute(): string
    {
        $key   = "oil_types.names.{$this->code}";
        $label = __($key);

        return $label === $key ? (string) $this->name : $label;
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
     * scopeFilter — mismo patrón que Customer, sobre la tabla oil_types.
     * Soporta name (multi-tag accent-insensitive), code (substring),
     * is_active (bool), rangos de fecha/id, filtros avanzados y favoritos.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'oil_types';

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

        $query->when($request->filled('code'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.code LIKE ?" : "{$tbl}.code LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->code)]);
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
        if (in_array($sort, ['id', 'name', 'code', 'is_active', 'sort_order', 'created_at', 'updated_at']) && in_array($direction, ['asc', 'desc'])) {
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
            ['key' => 'name',       'label' => __('oil_types.name'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'code',       'label' => __('oil_types.code'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',  'label' => __('oil_types.is_active'), 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
