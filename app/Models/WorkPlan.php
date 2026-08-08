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
 * WorkPlan — el plan de trabajo del día: la tarea que una empresa contratista
 * ejecuta en una sede, con su cuadrilla y sus formatos de seguridad.
 *
 * NO tiene `name` ni `is_active`: se identifica por `code` (PE24-0412-0458) y su
 * estado es de avance, no de catálogo — `is_done` (todo firmado y confirmado) e
 * `is_closed` (el supervisor lo dio por cerrado). Es PER-TENANT, por eso usa
 * BelongsToTenantOrGlobal. Mantiene SoftDeletes + Auditable + HasFavorites.
 */
class WorkPlan extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\Lockable;

    protected string $auditModule = 'work_plans';


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

    /**
     * Texto traducido del estado — consumido por exports (CSV/Excel/PDF/Word).
     * En un plan "estado" significa avance, no si el catálogo está habilitado.
     */
    public function getStateTextAttribute(): string
    {
        return $this->is_done ? __('work_plans.state_done') : __('work_plans.state_pending');
    }

    /**
     * scopeFilter — mismo patrón que Customer, sobre la tabla work_plans.
     *
     * El buscador rápido va por `search` y no por `name`: un plan no tiene
     * nombre, se lo busca por su código (PE24-0412-0458), por la orden de
     * servicio o por un pedazo de la descripción del trabajo.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'work_plans';

        $query->when($request->filled('search'), function ($q) use ($request, $isPgsql, $tbl) {
            $terms = is_array($request->search) ? $request->search : [$request->search];
            $terms = array_filter($terms, fn ($n) => $n !== '');
            if (empty($terms)) return;
            $q->where(function ($qq) use ($terms, $isPgsql, $tbl) {
                foreach ($terms as $term) {
                    $needle = LikeQuery::contains((string) $term);
                    foreach (['code', 'num_os', 'description'] as $col) {
                        if ($isPgsql) {
                            $qq->orWhereRaw("unaccent(lower({$tbl}.{$col})) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $qq->orWhereRaw("{$tbl}.{$col} LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                }
            });
        });

        $query->when($request->filled('code'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.code LIKE ?" : "{$tbl}.code LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->code)]);
        });

        $query->when($request->filled('num_os'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.num_os LIKE ?" : "{$tbl}.num_os LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->num_os)]);
        });

        // Selectores de obra: llegan como array (multiselect) o valor suelto.
        foreach (['company_id', 'work_type_id', 'work_location_id', 'work_area_id', 'workstation_id'] as $fk) {
            $query->when($request->filled($fk), function ($q) use ($request, $tbl, $fk) {
                $ids = array_filter((array) $request->input($fk), fn ($v) => $v !== '' && $v !== null);
                if (empty($ids)) return;
                $q->whereIn("{$tbl}.{$fk}", array_map('intval', $ids));
            });
        }

        $query->when($request->filled('is_done'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.is_done", filter_var($request->is_done, FILTER_VALIDATE_BOOLEAN));
        });

        $query->when($request->filled('is_closed'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.is_closed", filter_var($request->is_closed, FILTER_VALIDATE_BOOLEAN));
        });

        // Fecha del trabajo (no la de alta del registro): es la que usa el
        // supervisor para encontrar "los planes de esta semana".
        $query->when($request->filled('date_from'), fn ($q) => $q->where("{$tbl}.date_start", '>=', $request->date_from));
        $query->when($request->filled('date_to'),   fn ($q) => $q->where("{$tbl}.date_start", '<=', $request->date_to));

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
        } elseif ($sort === 'company') {
            $query->leftJoin('companies', "{$tbl}.company_id", '=', 'companies.id')
                  ->orderBy('companies.name', $direction);
        } elseif ($sort === 'work_type') {
            $query->leftJoin('work_types', "{$tbl}.work_type_id", '=', 'work_types.id')
                  ->orderBy('work_types.code', $direction);
        } elseif ($sort === 'work_location') {
            $query->leftJoin('work_locations', "{$tbl}.work_location_id", '=', 'work_locations.id')
                  ->orderBy('work_locations.name', $direction);
        } elseif (in_array($sort, ['id', 'code', 'num_os', 'description', 'date_start', 'date_end', 'is_done', 'is_closed', 'created_at', 'updated_at'], true)) {
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /**
     * Campos que el builder de filtros avanzados ofrece. Las opciones de los
     * selectores (empresa, tipo de trabajo, sede) las inyecta el controller
     * porque dependen del tenant del usuario.
     *
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(array $opts = []): array
    {
        return [
            ['key' => 'code',             'label' => __('work_plans.code'),          'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'num_os',           'label' => __('work_plans.num_os'),        'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'description',      'label' => __('work_plans.description'),   'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'company_id',       'label' => __('work_plans.company'),       'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['companies'] ?? []],
            ['key' => 'work_type_id',     'label' => __('work_plans.work_type'),     'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['work_types'] ?? []],
            ['key' => 'work_location_id', 'label' => __('work_plans.work_location'), 'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['work_locations'] ?? []],
            ['key' => 'is_done',          'label' => __('work_plans.is_done'),       'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'is_closed',        'label' => __('work_plans.is_closed'),     'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'date_start',       'label' => __('work_plans.date_start'),    'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'date_end',         'label' => __('work_plans.date_end'),      'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'created_at',       'label' => __('global.created_at'),        'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at',       'label' => __('global.updated_at'),        'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    use SoftDeletes;

    protected $fillable = [
        'slug', 'country_id', 'company_id', 'work_type_id', 'work_location_id',
        'workstation_id', 'work_area_id', 'user_id', 'code', 'num_os', 'description',
        'date_start', 'date_end', 'is_closed', 'is_done', 'legacy_id',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    // Las fechas del plan son días de calendario, no instantes: se serializan
    // como Y-m-d para que el navegador no las corra un día al aplicar su zona.
    protected $casts = ['is_closed' => 'boolean', 'is_done' => 'boolean',
                        'date_start' => 'date:Y-m-d', 'date_end' => 'date:Y-m-d'];

    public function country() { return $this->belongsTo(Country::class); }
    public function company() { return $this->belongsTo(Company::class); }
    public function workType() { return $this->belongsTo(WorkType::class); }
    public function workLocation() { return $this->belongsTo(WorkLocation::class); }
    public function workstation() { return $this->belongsTo(Workstation::class); }
    public function workArea() { return $this->belongsTo(WorkArea::class); }
    /** Quien registró el plan (usuario del sistema, no persona de la cuadrilla). */
    public function user() { return $this->belongsTo(User::class); }
    public function people() { return $this->hasMany(WorkPlanPerson::class); }
    public function approvals() { return $this->hasMany(WorkPlanApproval::class); }
    public function submissions() { return $this->hasMany(FormSubmission::class); }
    /** Lo que se le añadió o se le quitó al estándar del tipo de trabajo. */
    public function formTemplateOverrides() { return $this->hasMany(WorkPlanFormTemplate::class); }

    /**
     * Si se puede tocar la composición del plan (cuadrilla, formatos exigidos,
     * aprobadores). Un plan cerrado, terminado o con candado administrativo ya
     * es un documento del archivo: se consulta, no se arma.
     */
    public function isOpenForSetup(): bool
    {
        return ! $this->is_closed && ! $this->is_done && $this->locked_at === null;
    }

    /** Por qué está cerrado — para poder decírselo al usuario, no solo negarle. */
    public function setupBlockedReason(): ?string
    {
        return match (true) {
            $this->locked_at !== null => __('locks.cannot_edit_locked'),
            (bool) $this->is_done     => __('work_plans.setup_blocked_done'),
            $this->is_closed          => __('work_plans.setup_blocked_closed'),
            default                   => null,
        };
    }

    /**
     * Los formatos que este plan exige de verdad: el estándar del tipo de
     * trabajo, más los extras del plan, menos los que se le quitaron.
     *
     * Un formato con entrega SIEMPRE entra, aunque ya no esté en el estándar:
     * un papel que alguien llenó y firmó no puede desaparecer de la ficha
     * porque después se haya cambiado el catálogo.
     *
     * @return \Illuminate\Support\Collection<int, array{template: FormTemplate, is_required: bool, source: string}>
     */
    public function expectedFormTemplates(): \Illuminate\Support\Collection
    {
        $overrides = ($this->relationLoaded('formTemplateOverrides')
            ? $this->formTemplateOverrides
            : $this->formTemplateOverrides()->get())->keyBy('form_template_id');

        $esperados = collect();

        $estandar = $this->workType ? $this->workType->formTemplates()->published()->get() : collect();

        foreach ($estandar as $plantilla) {
            $override = $overrides->get($plantilla->id);
            if ($override && ! $override->is_included) {
                continue;
            }
            $esperados->put($plantilla->id, [
                'template'    => $plantilla,
                'is_required' => (bool) ($override?->is_required ?? $plantilla->pivot->is_required),
                'source'      => 'work_type',
            ]);
        }

        $extraIds = $overrides->filter(fn ($o) => $o->is_included)
            ->keys()
            ->reject(fn ($id) => $esperados->has($id))
            ->all();

        foreach (FormTemplate::whereIn('id', $extraIds)->get() as $plantilla) {
            $esperados->put($plantilla->id, [
                'template'    => $plantilla,
                'is_required' => (bool) $overrides[$plantilla->id]->is_required,
                'source'      => 'extra',
            ]);
        }

        // Red de seguridad: lo que ya se llenó se sigue viendo aunque el
        // catálogo haya cambiado después.
        $conEntrega = $this->submissions()->pluck('form_template_id')
            ->reject(fn ($id) => $esperados->has($id))
            ->all();

        foreach (FormTemplate::whereIn('id', $conEntrega)->get() as $plantilla) {
            $esperados->put($plantilla->id, [
                'template'    => $plantilla,
                'is_required' => false,
                'source'      => 'submitted',
            ]);
        }

        return $esperados;
    }

    /** El plan esta completo cuando todo formato exigido esta confirmado y toda
     *  aprobacion obligatoria esta aprobada. Lo decide el servidor, nunca el formulario. */
    public function isComplete(): bool
    {
        $faltanFormatos = $this->submissions()->where('status', '!=', 'confirmed')->exists();
        $faltanFirmas   = $this->approvals()->where('is_required', true)->where('is_approved', false)->exists();

        return ! $faltanFormatos && ! $faltanFirmas;
    }

}
