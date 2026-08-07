<?php

namespace App\Models;

use App\Support\LikeQuery;
use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use App\Traits\HasFavorites;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Transformer — el transformador. Pertenece a un Customer del saas-base,
 * por eso usa los mismos traits per-tenant (tenant scope, auditoría, soft-delete).
 *
 * Es a la vez módulo de negocio CRUD (estilo Customer) y entidad del motor de
 * diagnóstico. El módulo opera sobre `serial` (identificador visible, el num_serie)
 * con `tag` como secundario; los demás campos del dominio (FKs/gases) viven en
 * la tabla y en $fillable, y los usa el motor. Los trafos no tienen estado
 * activo/inactivo: solo se eliminan (soft-delete).
 *
 * oil_type_id / transformer_type_id / voltage_kv son los EJES que el motor de
 * reglas usa para elegir el rule_set correcto. El conmutador vive en
 * tap_changer_type_id (catálogo oltc/detc/none), no en un booleano aparte.
 */
class Transformer extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant, HasFavorites, \App\Traits\RestrictedToAssignedCustomers, \App\Traits\Lockable;

    protected string $auditModule = 'transformers';

    protected $fillable = [
        'slug', 'serial', 'tag', 'customer_id', 'customer_substation_id',
        'oil_type_id', 'transformer_type_id', 'brand_id', 'tap_changer_type_id',
        'tap_changer_brand_id', 'tap_changer_model_id', 'tap_changer_technology_id',
        'connection_type_id', 'transformer_preservation_id',
        'voltage_kv', 'power_mva', 'manufacture_year', 'paper_type', 'phases', 'oil_treated_at',
        'health_index', 'health_rating', 'health_trend',
        'fault_type', 'gassing_rate', 'paper_dp', 'paper_life_years', 'ieee_condition',
        'dga_rate_sample_ids',
        'forecast_months', 'forecast_target',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'voltage_kv'   => 'decimal:2',
        'power_mva'    => 'decimal:2',
        'health_index' => 'decimal:2',
        'gassing_rate' => 'decimal:2',
        'paper_dp'     => 'integer',
        'paper_life_years' => 'decimal:1',
        'ieee_condition' => 'integer',
        'dga_rate_sample_ids' => 'array',
        'forecast_months' => 'decimal:1',
        'oil_treated_at' => 'date',
    ];

    // ─── Relaciones del motor de diagnóstico ────────────────────────────
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function oilType(): BelongsTo
    {
        return $this->belongsTo(OilType::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function tapChangerType(): BelongsTo
    {
        return $this->belongsTo(TapChangerType::class);
    }

    public function tapChangerBrand(): BelongsTo
    {
        return $this->belongsTo(TapChangerBrand::class);
    }

    public function tapChangerModel(): BelongsTo
    {
        return $this->belongsTo(TapChangerModel::class);
    }

    public function tapChangerTechnology(): BelongsTo
    {
        return $this->belongsTo(TapChangerTechnology::class);
    }

    public function connectionType(): BelongsTo
    {
        return $this->belongsTo(ConnectionType::class);
    }

    public function preservation(): BelongsTo
    {
        return $this->belongsTo(TransformerPreservation::class, 'transformer_preservation_id');
    }

    public function transformerType(): BelongsTo
    {
        return $this->belongsTo(TransformerType::class);
    }

    public function substation(): BelongsTo
    {
        return $this->belongsTo(CustomerSubstation::class, 'customer_substation_id');
    }

    public function chromatographicals(): HasMany
    {
        return $this->hasMany(Chromatographical::class)->orderBy('sample_date', 'desc');
    }

    /** La muestra de cromatografía más reciente. */
    public function latestChromatographical()
    {
        return $this->chromatographicals()->first();
    }

    public function furanos(): HasMany
    {
        return $this->hasMany(Furano::class)->orderBy('sample_date', 'desc');
    }

    /** La muestra de furanos más reciente. */
    public function latestFurano()
    {
        return $this->furanos()->first();
    }

    public function fiquis(): HasMany
    {
        return $this->hasMany(Fiqui::class)->orderBy('sample_date', 'desc');
    }

    /** Bitácora: eventos/comentarios (línea de tiempo + kanban). */
    public function events(): HasMany
    {
        return $this->hasMany(TransformerEvent::class)->orderBy('starts_at', 'desc');
    }

    /**
     * Nombre de archivo seguro para descargas de reporte PDF. El serial real puede
     * traer "/" u otros caracteres (ej. "ET09618/1") que Symfony rechaza en el
     * Content-Disposition → 500. Sanitiza a [A-Za-z0-9_.-].
     */
    public function reportFilename(string $prefix, string $ext = 'pdf'): string
    {
        $base = (string) ($this->serial ?: $this->slug);
        $safe = trim(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $base), '-');

        return $prefix . '-' . ($safe !== '' ? $safe : 'reporte') . '.' . $ext;
    }

    /** La muestra fisicoquímica más reciente. */
    public function latestFiqui()
    {
        return $this->fiquis()->first();
    }

    public function fpots(): HasMany
    {
        return $this->hasMany(Fpot::class)->orderBy('sample_date', 'desc');
    }

    /** La muestra de factor de potencia más reciente. */
    public function latestFpot()
    {
        return $this->fpots()->first();
    }

    // ─── Patrón módulo de negocio (igual que Customer) ──────────────────
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

        // Al borrar definitivamente un trafo, limpia su caché de gráficos del
        // informe (evita archivos huérfanos acumulándose en disco).
        static::forceDeleted(function ($model) {
            app(\App\Services\Reports\ReportChartCache::class)->forget($model->id);
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

    /**
     * scopeFilter — mismo patrón que Customer, sobre la tabla transformers.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'transformers';

        // Buscador global tipo Google: UN término que matchea (OR) en serie, tag,
        // cliente, marca, tipo de trafo y tipo de aceite. Se combina con AND junto
        // al resto de filtros y al builder. unaccent ILIKE en pgsql.
        $query->when($request->filled('q'), function ($q) use ($request, $isPgsql, $tbl) {
            $needle = LikeQuery::contains((string) $request->q);
            $like = fn ($expr) => $isPgsql
                ? "unaccent(lower({$expr})) LIKE unaccent(lower(?))"
                : "{$expr} LIKE ? ESCAPE '\\'";
            $q->where(function ($qq) use ($needle, $like, $tbl) {
                $qq->orWhereRaw($like("{$tbl}.serial"), [$needle])
                   ->orWhereRaw($like("{$tbl}.tag"), [$needle])
                   ->orWhereHas('customer',        fn ($c) => $c->whereRaw($like('name'), [$needle]))
                   ->orWhereHas('brand',           fn ($c) => $c->whereRaw($like('name'), [$needle]))
                   ->orWhereHas('transformerType', fn ($c) => $c->whereRaw($like('name'), [$needle]))
                   ->orWhereHas('oilType',         fn ($c) => $c->whereRaw($like('name'), [$needle]));
            });
        });

        $query->when($request->filled('serial'), function ($q) use ($request, $isPgsql, $tbl) {
            $serials = is_array($request->serial) ? $request->serial : [$request->serial];
            $serials = array_filter($serials, fn ($n) => $n !== '');
            if (empty($serials)) return;
            $q->where(function ($qq) use ($serials, $isPgsql, $tbl) {
                foreach ($serials as $serial) {
                    $needle = LikeQuery::contains((string) $serial);
                    if ($isPgsql) {
                        // Sin "ESCAPE '\'" explícito: en Postgres el escape por
                        // defecto de LIKE YA es backslash (igual que LikeQuery).
                        // Ese literal '\' en raw SQL rompe el parser de placeholders
                        // de PDO cuando hay >1 cláusula LIKE (serial + tag) → HY093.
                        $qq->orWhereRaw("unaccent(lower({$tbl}.serial)) LIKE unaccent(lower(?))", [$needle]);
                    } else {
                        $qq->orWhereRaw("{$tbl}.serial LIKE ? ESCAPE '\\'", [$needle]);
                    }
                }
            });
        });

        // Búsqueda por TAG (texto, igual que serial).
        $query->when($request->filled('tag'), function ($q) use ($request, $isPgsql, $tbl) {
            $tags = is_array($request->tag) ? $request->tag : [$request->tag];
            $tags = array_filter($tags, fn ($n) => $n !== '');
            if (empty($tags)) return;
            $q->where(function ($qq) use ($tags, $isPgsql, $tbl) {
                foreach ($tags as $tag) {
                    $needle = LikeQuery::contains((string) $tag);
                    $isPgsql
                        ? $qq->orWhereRaw("unaccent(lower({$tbl}.tag)) LIKE unaccent(lower(?))", [$needle])
                        : $qq->orWhereRaw("{$tbl}.tag LIKE ? ESCAPE '\\'", [$needle]);
                }
            });
        });

        // Multiselects por relación (cliente, subestación, tipo de trafo, aceite,
        // marca, conmutador, conexión, preservación).
        foreach ([
            'customer_id', 'customer_substation_id', 'transformer_type_id', 'oil_type_id',
            'brand_id', 'tap_changer_type_id', 'connection_type_id', 'transformer_preservation_id',
        ] as $fk) {
            $query->when($request->filled($fk), function ($q) use ($request, $tbl, $fk) {
                $ids = is_array($request->$fk) ? $request->$fk : [$request->$fk];
                $ids = array_filter($ids, fn ($v) => $v !== '' && $v !== null);
                if (!empty($ids)) $q->whereIn("{$tbl}.{$fk}", $ids);
            });
        }

        // Multiselects por columna (fases, estado de salud).
        foreach (['phases', 'health_rating'] as $col) {
            $query->when($request->filled($col), function ($q) use ($request, $tbl, $col) {
                $vals = is_array($request->$col) ? $request->$col : [$request->$col];
                $vals = array_filter($vals, fn ($v) => $v !== '' && $v !== null);
                if (!empty($vals)) $q->whereIn("{$tbl}.{$col}", $vals);
            });
        }

        // Chips rápidos de condición: Buenos (3-4), Regulares (2), Malos (0-1),
        // Sin pruebas (sin HI → health_rating NULL).
        $query->when($request->filled('health_band'), function ($q) use ($request, $tbl) {
            match ($request->health_band) {
                'good'    => $q->whereIn("{$tbl}.health_rating", [3, 4]),
                'regular' => $q->where("{$tbl}.health_rating", 2),
                'bad'     => $q->whereIn("{$tbl}.health_rating", [0, 1]),
                'none'    => $q->whereNull("{$tbl}.health_rating"),
                default   => null,
            };
        });

        // Rangos numéricos (tensión kV, potencia MVA, año de fabricación).
        $query->when($request->filled('voltage_min'), fn ($q) => $q->where("{$tbl}.voltage_kv", '>=', (float) $request->voltage_min));
        $query->when($request->filled('voltage_max'), fn ($q) => $q->where("{$tbl}.voltage_kv", '<=', (float) $request->voltage_max));
        $query->when($request->filled('power_min'),   fn ($q) => $q->where("{$tbl}.power_mva", '>=', (float) $request->power_min));
        $query->when($request->filled('power_max'),   fn ($q) => $q->where("{$tbl}.power_mva", '<=', (float) $request->power_max));
        $query->when($request->filled('year_min'),    fn ($q) => $q->where("{$tbl}.manufacture_year", '>=', (int) $request->year_min));
        $query->when($request->filled('year_max'),    fn ($q) => $q->where("{$tbl}.manufacture_year", '<=', (int) $request->year_max));

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
        $direction = in_array($request->get('direction'), ['asc', 'desc'], true) ? $request->get('direction') : 'desc';

        // Columnas directas de la tabla.
        $directCols = ['id', 'serial', 'tag', 'phases', 'voltage_kv', 'power_mva', 'manufacture_year', 'health_index', 'health_rating', 'created_at', 'updated_at'];
        // Sort por nombre del relacionado (cliente/tipo/aceite) via left join. Cada
        // FK es 1:1, así que el join no multiplica filas. [tabla, fk, columna].
        $relSorts = [
            'customer'         => ['customers', 'customer_id', 'name'],
            'transformer_type' => ['transformer_types', 'transformer_type_id', 'name'],
            'oil_type'         => ['oil_types', 'oil_type_id', 'name'],
            'brand'            => ['brands', 'brand_id', 'name'],
            'connection_type'  => ['connection_types', 'connection_type_id', 'name'],
            'tap_changer_type' => ['tap_changer_types', 'tap_changer_type_id', 'name'],
            'preservation'     => ['transformer_preservations', 'transformer_preservation_id', 'name'],
            'tenant'           => ['tenants', 'tenant_id', 'name'],
        ];

        if (in_array($sort, $directCols, true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        } elseif (isset($relSorts[$sort])) {
            [$relTable, $fk, $col] = $relSorts[$sort];
            $alias = "srt_{$sort}";
            // El controller ya hace select('transformers.*'), así que el join no
            // contamina columnas.
            $query->leftJoin("{$relTable} as {$alias}", "{$alias}.id", '=', "{$tbl}.{$fk}")
                  ->orderBy("{$alias}.{$col}", $direction);
        }

        return $query;
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    /**
     * Schema de campos para el drawer de "Filtros avanzados". Los campos FK/enum
     * reciben sus `options` desde el controller (que ya las carga para los
     * multiselects). Sin opciones (ej. al validar el advanced_where en
     * scopeFilter) los selects quedan vacíos pero la allowlist sigue válida.
     *
     * @param array $opts opciones por campo: customers, substations,
     *   transformer_types, oil_types, brands, connection_types,
     *   tap_changer_types, preservations.
     */
    public static function filterSchema(array $opts = []): array
    {
        $num  = ['=', '!=', '>', '<', '>=', '<='];
        $enum = ['=', '!=', 'in'];

        $phases = [
            ['value' => 'single', 'label' => __('transformers.phases_single')],
            ['value' => 'two',    'label' => __('transformers.phases_two')],
            ['value' => 'three',  'label' => __('transformers.phases_three')],
        ];
        // Rating ENTERO (4=mejor … 0=peor); la etiqueta sale del rating (editable por idioma).
        $healthRatings = collect([4, 3, 2, 1, 0])->map(fn ($r) => [
            'value' => $r,
            'label' => \App\Support\Diagnostics\ConditionLabel::for($r),
        ])->all();

        return [
            ['key' => 'serial',                      'label' => __('transformers.serial'),           'type' => 'string', 'operators' => ['=', '!=', 'contains']],
            ['key' => 'tag',                         'label' => __('transformers.tag'),              'type' => 'string', 'operators' => ['=', '!=', 'contains']],
            ['key' => 'customer_id',                 'label' => __('transformers.customer'),         'type' => 'enum',   'operators' => $enum, 'options' => $opts['customers'] ?? []],
            ['key' => 'customer_substation_id',      'label' => __('transformers.substation'),       'type' => 'enum',   'operators' => $enum, 'options' => $opts['substations'] ?? []],
            ['key' => 'transformer_type_id',         'label' => __('transformers.transformer_type'), 'type' => 'enum',   'operators' => $enum, 'options' => $opts['transformer_types'] ?? []],
            ['key' => 'oil_type_id',                 'label' => __('transformers.oil_type'),         'type' => 'enum',   'operators' => $enum, 'options' => $opts['oil_types'] ?? []],
            ['key' => 'brand_id',                    'label' => __('transformers.brand'),            'type' => 'enum',   'operators' => $enum, 'options' => $opts['brands'] ?? []],
            ['key' => 'connection_type_id',          'label' => __('transformers.connection_type'),  'type' => 'enum',   'operators' => $enum, 'options' => $opts['connection_types'] ?? []],
            ['key' => 'tap_changer_type_id',         'label' => __('transformers.tap_changer_type'), 'type' => 'enum',   'operators' => $enum, 'options' => $opts['tap_changer_types'] ?? []],
            ['key' => 'transformer_preservation_id', 'label' => __('transformers.preservation'),     'type' => 'enum',   'operators' => $enum, 'options' => $opts['preservations'] ?? []],
            ['key' => 'phases',                      'label' => __('transformers.phases'),           'type' => 'enum',   'operators' => $enum, 'options' => $phases],
            ['key' => 'health_rating',               'label' => __('transformers.health_state'),     'type' => 'enum',   'operators' => $enum, 'options' => $healthRatings],
            ['key' => 'voltage_kv',                  'label' => __('transformers.voltage_kv'),       'type' => 'number', 'operators' => $num],
            ['key' => 'power_mva',                   'label' => __('transformers.power_mva'),        'type' => 'number', 'operators' => $num],
            ['key' => 'manufacture_year',            'label' => __('transformers.manufacture_year'), 'type' => 'number', 'operators' => $num],
            ['key' => 'health_index',                'label' => __('transformers.health_index'),     'type' => 'number', 'operators' => $num],
            ['key' => 'created_at',                  'label' => __('global.created_at'),             'type' => 'date',   'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at',                  'label' => __('global.updated_at'),             'type' => 'date',   'operators' => ['>', '<', '>=', '<=']],
        ];
    }
}
