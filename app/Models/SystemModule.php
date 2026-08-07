<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\HasFavorites;
use App\Traits\HasDependents;

class SystemModule extends Model
{
    use HasFactory, SoftDeletes, Auditable, HasFavorites, HasDependents;

    protected string $auditModule = 'system_modules';

    protected $fillable = [
        'name',
        'permission_key',
        'is_active',
        'created_by',
        'deleted_by',
        'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function dependents(): array
    {
        // Sin FKs entrantes — los permissions referencian el `permission_key`
        // por string, no por FK. El Observer maneja el cleanup en delete.
        return [];
    }

    protected static function booted()
    {
        static::creating(function ($module) {
            $attempts = 0;
            do {
                $slug = Str::random(22);
                $attempts++;
            } while ($attempts < 5 && SystemModule::withTrashed()->where('slug', $slug)->exists());
            $module->slug = $slug;
        });

        static::deleted(function ($module) {
            if (!$module->isForceDeleting()) return;
            \App\Models\UserFavorite::where('favoritable_type', static::class)
                ->where('favoritable_id', $module->id)
                ->delete();
            \App\Models\UserRecentView::where('viewable_type', static::class)
                ->where('viewable_id', $module->id)
                ->delete();
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by')->withTrashed();
    }

    /**
     * Auto-deriva permission_key del name: PascalCaseSingular → snake_case_plural.
     * "System Module" → name="SystemModule", permission_key="system_modules".
     * "Patient" → name="Patient", permission_key="patients".
     */
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = Str::studly(Str::singular($value));
        $snake = Str::snake(Str::singular($value));
        $this->attributes['permission_key'] = Str::plural($snake);
    }

    /** Lista de permisos Spatie asociados a este módulo. */
    public function getPermissionsAttribute()
    {
        return Permission::where('name', 'like', "{$this->permission_key}.%")
            ->orderBy('id')
            ->get();
    }

    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Filtros: name (multi-tag, unaccent), permission_key (multi-tag),
     * is_active, date ranges, id range, only_favorites, sort.
     */
    public function scopeFilter(Builder $query, Request|array $filters): Builder
    {
        if (is_array($filters)) {
            $filters = new Request($filters);
        }

        $tbl = 'system_modules';

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

        if ($filters->filled('permission_key')) {
            $keys = is_array($filters->permission_key) ? $filters->permission_key : [$filters->permission_key];
            $keys = array_filter(array_map('trim', $keys), fn($k) => $k !== '');
            if (count($keys) > 0) {
                $query->where(function ($q) use ($keys, $tbl) {
                    foreach ($keys as $k) {
                        $q->orWhereRaw(config('database.default') === 'pgsql' ? "{$tbl}.permission_key LIKE ?" : "{$tbl}.permission_key LIKE ? ESCAPE '\\'", [LikeQuery::contains(strtolower($k))]);
                    }
                });
            }
        }

        if ($filters->filled('is_active')) {
            $query->where("{$tbl}.is_active", filter_var($filters->is_active, FILTER_VALIDATE_BOOLEAN));
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
        if (in_array($sort, ['id', 'name', 'permission_key', 'is_active', 'created_at', 'updated_at', 'deleted_at']) && in_array($direction, ['asc', 'desc'])) {
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
            ['key' => 'name',           'label' => __('system_modules.name'),           'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'permission_key', 'label' => __('system_modules.permission_key'), 'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',      'label' => __('global.active'),                 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at',     'label' => __('global.created_at'),             'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at',     'label' => __('global.updated_at'),             'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
