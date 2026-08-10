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
 * Brand — catálogo de marcas por workspace.
 *
 * Viene de TRAFODEX (allí eran los fabricantes de transformadores) y **sigue en
 * el proyecto porque es la plantilla que clona `make:module`**: borrarla deja el
 * generador inservible. Qué papel tiene en DOCUFIZ está SIN DECIDIR — ver
 * `docs/PENDIENTES.md`, punto 2. Hasta que se decida, no se le da un dominio
 * nuevo ni se le cambia el nombre.
 *
 * Catálogo PER-TENANT (cada workspace tiene el suyo) vía BelongsToTenantOrGlobal
 * con bypass de super. SoftDeletes + Auditable + HasFavorites + Lockable.
 * Campos: `name`, `code` (identificador corto), `sort_order`, `is_active`,
 * `tenant_id`.
 *
 * OJO al tocar este modelo: lo que se rompa aquí se copia tal cual al próximo
 * módulo que genere `make:module`.
 */
class Brand extends Model
{
    // `HasDependents` con la lista vacía a propósito: no hay ni una clave ajena
    // que apunte a `brands`, así que al borrar no hay nada que avisar. Va igual
    // para que el contrato sea el mismo que en sus hermanos —la pantalla de
    // borrado llama a `countDependents()`— y para que el día que alguien le
    // cuelgue una tabla, el sitio donde declararla ya esté aquí. Es el molde
    // del generador: lo que falte aquí, falta en el próximo módulo.
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\Lockable, \App\Traits\HasDependents;

    protected string $auditModule = 'brands';

    protected $fillable = [
        'slug', 'name', 'code', 'is_active', 'sort_order', 'tenant_id',
        'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];


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
     * scopeFilter — mismo patrón que Customer, sobre la tabla brands.
     * Soporta name (multi-tag accent-insensitive), code (substring),
     * is_active (bool), rangos de fecha/id, filtros avanzados y favoritos.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'brands';

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
        if ($sort === 'tenant' && in_array($direction, ['asc', 'desc'])) {
            // Orden por workspace: nombre vía left join (nulls = global).
            $query->leftJoin('tenants', "{$tbl}.tenant_id", '=', 'tenants.id')
                  ->orderBy('tenants.name', $direction);
        } elseif (in_array($sort, ['id', 'name', 'code', 'is_active', 'sort_order', 'created_at', 'updated_at']) && in_array($direction, ['asc', 'desc'])) {
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
            ['key' => 'name',       'label' => __('brands.name'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'code',       'label' => __('brands.code'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'sort_order', 'label' => __('brands.sort_order'), 'type' => 'number',  'operators' => ['=', '!=', '>', '<', '>=', '<=']],
            ['key' => 'is_active',  'label' => __('brands.is_active'), 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
