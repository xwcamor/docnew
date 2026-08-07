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

/**
 * Setting — editor key-value tipado para configuración global del sistema.
 * Solo super lo gestiona vía CRUD; el resto del código lo lee con
 * Setting::get('key') o Setting::getBool('key').
 */
class Setting extends Model
{
    use HasFactory, SoftDeletes, Auditable, HasFavorites, HasDependents;

    protected string $auditModule = 'settings';

    public const TYPES = ['string', 'int', 'bool', 'json'];

    protected $fillable = [
        'key',
        'name',
        'type',
        'value',
        'group',
        'description',
        'is_secret',
        'is_active',
        'created_by',
        'deleted_by',
        'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_secret' => 'boolean',
    ];

    public function dependents(): array
    {
        return [];
    }

    protected static function booted()
    {
        static::creating(function ($setting) {
            $attempts = 0;
            do {
                $slug = Str::random(22);
                $attempts++;
            } while ($attempts < 5 && Setting::withTrashed()->where('slug', $slug)->exists());
            $setting->slug = $slug;
        });

        static::deleted(function ($setting) {
            self::flushCache();
            if (!$setting->isForceDeleting()) return;
            \App\Models\UserFavorite::where('favoritable_type', static::class)
                ->where('favoritable_id', $setting->id)
                ->delete();
            \App\Models\UserRecentView::where('viewable_type', static::class)
                ->where('viewable_id', $setting->id)
                ->delete();
        });

        static::saved(fn () => self::flushCache());
        static::restored(fn () => self::flushCache());
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

    public function getStateTextAttribute(): string
    {
        return $this->is_active ? __('global.active') : __('global.inactive');
    }

    // ─── Typed value casting ──────────────────────────────────────────────────

    /**
     * Devuelve el valor casteado según `type`. Para usar desde código:
     *   Setting::where('key', 'app.maintenance_mode')->first()->castedValue
     */
    public function getCastedValueAttribute(): mixed
    {
        return self::castValueByType($this->value, $this->type);
    }

    /**
     * Request-scoped cache. Una sola query por key por request — los settings
     * cambian raramente, no vale ir a DB en cada llamada. Se invalida en
     * saved()/deleted() del modelo (ver booted()).
     */
    private static array $requestCache = [];

    /**
     * Static helper para leer un setting por key con casting automático.
     *   Setting::get('app.maintenance_mode', false)
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$requestCache)) {
            $cached = self::$requestCache[$key];
            return $cached === null ? $default : $cached;
        }

        $setting = static::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        $casted = $setting ? self::castValueByType($setting->value, $setting->type) : null;
        self::$requestCache[$key] = $casted;

        return $casted === null ? $default : $casted;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        return (bool) static::get($key, $default);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        return (int) static::get($key, $default);
    }

    /**
     * Limpia el cache request-scoped. Lo llaman los hooks del modelo cuando
     * un Setting cambia, para que la próxima lectura traiga el valor fresco.
     */
    public static function flushCache(): void
    {
        self::$requestCache = [];
    }

    /**
     * Límites de export con cascada: Setting global → config/{module}.php.
     * Permite override en runtime (super) y mantiene el config como fallback.
     *
     * Setting keys:
     *   exports.max_csv_rows   (0 = sin límite, streaming)
     *   exports.max_excel_rows (default 25000)
     *   exports.max_pdf_rows   (default 5000)
     *   exports.max_word_rows  (default 10000)
     */
    public static function getExportLimits(string $module): array
    {
        $configKey = $module === 'settings' ? 'settings_module' : $module;
        return [
            'csv'   => static::getInt('exports.max_csv_rows',   (int) config("{$configKey}.export_limits.csv",   0)),
            'excel' => static::getInt('exports.max_excel_rows', (int) config("{$configKey}.export_limits.excel", 25000)),
            'pdf'   => static::getInt('exports.max_pdf_rows',   (int) config("{$configKey}.export_limits.pdf",   5000)),
            'word'  => static::getInt('exports.max_word_rows',  (int) config("{$configKey}.export_limits.word",  10000)),
        ];
    }

    public static function getExportLimit(string $module, string $format): int
    {
        return static::getExportLimits($module)[$format] ?? 0;
    }

    protected static function castValueByType(?string $value, string $type): mixed
    {
        if ($value === null) return null;
        return match ($type) {
            'bool'   => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int'    => (int) $value,
            'json'   => json_decode($value, true),
            default  => $value,
        };
    }

    /**
     * Filtros: name (multi-tag, unaccent), key (multi-tag, exacto),
     * type (multi-select), group (multi-select), is_secret, is_active,
     * date ranges, id range, only_favorites, sort.
     */
    public function scopeFilter(Builder $query, Request|array $filters): Builder
    {
        if (is_array($filters)) {
            $filters = new Request($filters);
        }

        $tbl = 'settings';

        if ($filters->filled('name')) {
            $names = is_array($filters->name) ? $filters->name : [$filters->name];
            $names = array_filter(array_map('trim', $names), fn($n) => $n !== '');
            if (count($names) > 0) {
                $isPgsql = DB::getDriverName() === 'pgsql';
                $query->where(function ($q) use ($names, $isPgsql, $tbl) {
                    foreach ($names as $name) {
                        $needle = LikeQuery::contains((string) $name);
                        if ($isPgsql) {
                            $q->orWhereRaw("unaccent(lower({$tbl}.name)) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $q->orWhereRaw("{$tbl}.name LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                });
            }
        }

        if ($filters->filled('key')) {
            $keys = is_array($filters->key) ? $filters->key : [$filters->key];
            $keys = array_filter(array_map('trim', $keys), fn($k) => $k !== '');
            if (count($keys) > 0) {
                $query->where(function ($q) use ($keys, $tbl) {
                    foreach ($keys as $k) {
                        $q->orWhereRaw(config('database.default') === 'pgsql' ? "{$tbl}.key LIKE ?" : "{$tbl}.key LIKE ? ESCAPE '\\'", [LikeQuery::contains(strtolower($k))]);
                    }
                });
            }
        }

        if ($filters->filled('type')) {
            $types = is_array($filters->type) ? $filters->type : [$filters->type];
            $types = array_filter(array_map(fn($t) => strtolower(trim($t)), $types));
            if (count($types) > 0) {
                $query->whereIn("{$tbl}.type", $types);
            }
        }

        if ($filters->filled('group')) {
            $groups = is_array($filters->group) ? $filters->group : [$filters->group];
            $groups = array_filter(array_map('trim', $groups), fn($g) => $g !== '');
            if (count($groups) > 0) {
                $query->whereIn("{$tbl}.group", $groups);
            }
        }

        if ($filters->filled('is_active')) {
            $query->where("{$tbl}.is_active", filter_var($filters->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($filters->filled('is_secret')) {
            $query->where("{$tbl}.is_secret", filter_var($filters->is_secret, FILTER_VALIDATE_BOOLEAN));
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
        if (in_array($sort, ['id', 'name', 'key', 'type', 'group', 'is_active', 'created_at', 'updated_at', 'deleted_at']) && in_array($direction, ['asc', 'desc'])) {
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
            ['key' => 'name',       'label' => __('settings.name'),     'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'key',        'label' => __('settings.key'),      'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'group',      'label' => __('settings.group'),    'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'is_active',  'label' => __('global.active'),     'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'), 'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'), 'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
