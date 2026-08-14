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

        // Reabiertos: los que alguien volvio a abrir para corregir algo.
        //
        // No es un tercer estado —el plan sigue en curso— pero si es el grupo
        // que hay que poder aislar: son los unicos planes cuyo documento cambio
        // DESPUES de haberse dado por terminado, y eso es justo lo que hay que
        // poder explicar delante de un inspector. `reopened_at` ya se guardaba
        // con quien y cuando; lo que no habia era forma de listarlos.
        $query->when($request->filled('reopened'), function ($q) use ($request, $tbl) {
            filter_var($request->reopened, FILTER_VALIDATE_BOOLEAN)
                ? $q->whereNotNull("{$tbl}.reopened_at")
                : $q->whereNull("{$tbl}.reopened_at");
        });

        // Fecha del trabajo (no la de alta del registro): es la que usa el
        // supervisor para encontrar "los planes de esta semana".
        // Se filtra por día aunque la columna lleve hora: pedir «hasta el 6» y
        // perder el plan que empezó el 6 a las 12:26 no lo entendería nadie.
        $query->when($request->filled('date_from'), fn ($q) => $q->where("{$tbl}.date_start", '>=', substr((string) $request->date_from, 0, 10) . ' 00:00:00'));
        $query->when($request->filled('date_to'),   fn ($q) => $q->where("{$tbl}.date_start", '<=', substr((string) $request->date_to, 0, 10) . ' 23:59:59'));

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
            ['key' => 'updated_at',       'label' => __('global.updated_at'),        'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    use SoftDeletes;

    /**
     * Quien responde por la cuadrilla de este plan.
     *
     * Del plan y no de la persona: un electricista que va solo a la obra es el
     * representante ese dia y uno mas al siguiente. Y no es una aprobacion —no
     * recoge firma propia—, sino un puntero a alguien que ya firmo como
     * trabajador de este mismo plan.
     */
    public function crewRepresentative(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Person::class, 'crew_representative_person_id');
    }

    protected $fillable = [
        'slug', 'country_id', 'company_id', 'crew_representative_person_id',
        'work_type_id', 'work_location_id',
        'workstation_id', 'work_area_id', 'user_id', 'code', 'num_os', 'description',
        'date_start', 'date_end', 'is_closed', 'is_done', 'reopened_at', 'reopened_by', 'legacy_id',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    // Las fechas del plan llevan hora, y la hora importa: el sistema anterior
    // las llama «Fecha y Hora de Inicio» y «Fecha y Hora de Fin», y de restar
    // una de otra sale el «Tiempo Trabajado» que la ficha enseñaba. Se
    // serializan sin zona (Y-m-d H:i) porque son la hora de la obra, no un
    // instante UTC que el navegador deba reinterpretar.
    protected $casts = ['is_closed' => 'boolean', 'is_done' => 'boolean', 'reopened_at' => 'datetime',
                        'date_start' => 'datetime:Y-m-d H:i', 'date_end' => 'datetime:Y-m-d H:i'];

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
    /** Quien reabrio el plan, para poder decirlo en la ficha. */
    public function reopenedBy()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isOpenForSetup(): bool
    {
        // Dos cosas distintas bloquean un plan, y las dos siguen contando:
        //
        //   `is_closed`/`is_done` — el plan termino. Es su ciclo de vida, se
        //       calcula solo, y lo reabre «Reabrir».
        //   `locked_at`           — un administrador congelo el registro. Es el
        //       trait Lockable, que existe en todo el sistema y ademas protege
        //       la edicion y el borrado masivos.
        //
        // Lo que estaba mal NO era que hubiera dos: era que la ficha enseñaba
        // el boton del segundo mientras el que bloqueaba de verdad era el
        // primero. El dueno del producto cerro un plan terminado, lo
        // desbloqueo, y seguia sin poder tocar nada — estaba quitando el
        // candado que no era, y del otro no habia forma de salir.
        //
        // La regla ahora es de PANTALLA y esta en Show.vue: se enseña un solo
        // control cada vez, y siempre el que manda. Plan terminado → «Reabrir»,
        // y el candado administrativo no sale. Plan en curso → el candado, que
        // ahi si es lo unico que puede estar bloqueandolo.
        return ! $this->is_closed && ! $this->is_done && $this->locked_at === null;
    }

    /**
     * Tiempo trabajado, en horas con dos decimales. `null` mientras falte una
     * de las dos fechas — que es lo normal en un plan que sigue abierto.
     *
     * Es la unica medida de cuanto duro el trabajo, y el sistema anterior la
     * enseñaba en su propia tarjeta de la ficha (`Plan#worked_time_hours`).
     */
    public function getWorkedHoursAttribute(): ?float
    {
        if (! $this->date_start || ! $this->date_end) {
            return null;
        }

        $segundos = $this->date_end->getTimestamp() - $this->date_start->getTimestamp();

        // Una fecha de fin anterior a la de inicio es un dato malo, no un
        // trabajo de duracion negativa. Se calla en vez de enseñar «-3 horas».
        return $segundos < 0 ? null : round($segundos / 3600, 2);
    }

    /**
     * El mismo tiempo, escrito como lo lee un supervisor: «2 horas, 30 minutos».
     * Los dias solo aparecen cuando los hay — y los hay: el plan mas largo de
     * los datos reales duro 5 dias.
     */
    public function getWorkedTimeAttribute(): ?string
    {
        if ($this->worked_hours === null) {
            return null;
        }

        $segundos = $this->date_end->getTimestamp() - $this->date_start->getTimestamp();
        $partes = [];

        foreach ([['time.days', intdiv($segundos, 86400)],
                  ['time.hours', intdiv($segundos % 86400, 3600)],
                  ['time.minutes', intdiv($segundos % 3600, 60)]] as [$clave, $cuantos]) {
            if ($cuantos > 0) {
                $partes[] = $cuantos . ' ' . trans_choice($clave, $cuantos);
            }
        }

        if ($partes === []) {
            return '0 ' . trans_choice('time.minutes', 0);
        }

        // «a, b y c» — la ultima coma se cambia por la conjuncion.
        $ultima = array_pop($partes);

        return $partes === [] ? $ultima : implode(', ', $partes) . ' ' . __('time.and') . ' ' . $ultima;
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
     * Y un plan CERRADO no hereda nada nuevo del catálogo. Es la regla que ya
     * estaba escrita en `WorkTypeService::planesAbiertos()` —«pedirle a un plan
     * cerrado un formato que se añadió hoy sería inventar un incumplimiento que
     * nunca existió»— y que sólo se aplicaba al texto del aviso: el cálculo de
     * aquí no miraba `is_closed`, así que marcar un formato nuevo en el tipo de
     * trabajo aparecía como pendiente en planes firmados hacía un año.
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

        if ($this->is_closed) {
            // Del estándar vigente sólo sobrevive lo que este plan conoció: lo
            // que tiene entrega y lo que se le ajustó a mano. No se pierde
            // nada por el camino —un plan sólo cierra con TODO lo esperado
            // confirmado (`WorkPlanCompletionService::puedeCerrarse()`), así
            // que todo lo que esperaba tiene su entrega— y lo que se filtra es
            // exactamente lo que se añadió al catálogo después de cerrarlo.
            $conEntrega = $this->submissions()->pluck('form_template_id')->all();

            $estandar = $estandar->filter(
                fn ($plantilla) => in_array($plantilla->id, $conEntrega) || $overrides->has($plantilla->id),
            );
        } else {
            // Un plan ABIERTO tampoco hereda DOCUMENTOS nuevos: espera los que
            // existían cuando se creó. Publicar cinco formatos y colgarlos del
            // tipo de trabajo los metía como pendientes en todos los planes
            // abiertos y reabiertos de ese tipo — planes que iban a cerrarse ese
            // día amanecían con cinco documentos «sin llenar» que nadie pidió
            // para esa jornada. El formato nuevo sale en el catálogo de la
            // ficha, apagado, y quien arma el plan lo enciende si esa obra lo
            // necesita (`WorkPlanSetupService::addFormTemplate`).
            //
            // La identidad de un documento es su CÓDIGO, no el id: una versión
            // nueva de un formato que el plan ya conocía no es un documento
            // nuevo y tiene que seguir llegando — si el plan aún no lo llenó, se
            // llena con la versión vigente, como siempre. Por eso se mira
            // cuándo nació la PRIMERA versión del código.
            $nacimientos = FormTemplate::query()
                ->whereIn('code', $estandar->pluck('code'))
                ->groupBy('code')
                ->selectRaw('code, min(created_at) as nacio')
                ->pluck('nacio', 'code');

            // Un documento del que este plan YA tiene entrega tambien es
            // conocido, tenga la fecha que tenga. No es un caso raro: los
            // planes migrados guardan el `created_at` de la v1 —anterior a que
            // estos formatos se sembraran aqui— y sin esta clausula un plan
            // reabierto de entonces perderia la exigencia de los cuatro
            // formatos que lleva llenando desde siempre.
            $codigosConEntrega = FormTemplate::query()
                ->whereIn('id', $this->submissions()->pluck('form_template_id'))
                ->pluck('code')
                ->flip();

            // Con un margen de minutos, y no al segundo. Dos motivos: el que se
            // ve —quien crea el plan y EN LA MISMA SENTADA da de alta el
            // formato que le faltaba quiere ese formato en ese plan— y el que
            // no: comparar al segundo hace que dos filas creadas en la misma
            // operacion caigan a lados distintos del corte segun donde pille el
            // tic del reloj. Lo que se quiere excluir son los documentos
            // publicados DIAS despues sobre planes que ya estaban andando, y a
            // esos el margen no los alcanza.
            $margen = $this->created_at?->clone()->addMinutes(10) ?? now();

            $estandar = $estandar->filter(
                fn ($plantilla) => $overrides->has($plantilla->id)
                    || $codigosConEntrega->has($plantilla->code)
                    || (($nacimientos[$plantilla->code] ?? null) !== null
                        && \Illuminate\Support\Carbon::parse($nacimientos[$plantilla->code])->lte($margen)),
            );
        }

        foreach ($estandar as $plantilla) {
            $override = $overrides->get($plantilla->id);

            // Lo que el tipo de trabajo marca obligatorio no se puede excluir
            // del plan, y aqui se ignora cualquier exclusion que hubiera.
            //
            // El servicio ya lo impide al quitar, pero esta comprobacion es la
            // que vale: hay planes migrados y ajustes hechos antes de que
            // existiera la regla, y un AST excluido en 2025 no puede seguir
            // sin aparecer ahora que sabemos que es obligatorio.
            $obligatorioDelTipo = (bool) $plantilla->pivot->is_required;

            if ($override && ! $override->is_included && ! $obligatorioDelTipo) {
                continue;
            }

            $esperados->put($plantilla->id, [
                'template'    => $plantilla,
                // Un ajuste del plan puede subir la exigencia, nunca bajarla:
                // si el tipo lo pide, se pide.
                'is_required' => $obligatorioDelTipo || (bool) ($override?->is_required ?? false),
                'source'      => 'work_type',
                'from_type_required' => $obligatorioDelTipo,
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
                // Añadido a mano a ESTE plan: quien lo puso lo puede quitar.
                'from_type_required' => false,
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
                'from_type_required' => false,
            ]);
        }

        return $this->unaVersionPorDocumento($esperados);
    }

    /**
     * Un documento, UNA fila — aunque el catálogo lleve dos versiones de él.
     *
     * Pasa en cuanto alguien edita un formato con planes a medias: la entrega
     * del plan apunta a la versión vieja (archivada al publicar la nueva) y el
     * tipo de trabajo ya apunta a la nueva. Sin esto, el plan enseñaba el MISMO
     * documento dos veces —el llenado, por la red de seguridad de arriba, y la
     * versión nueva como pendiente— y un plan que estaba terminado amanecía
     * con un formato «sin llenar» que ya se llenó y se firmó.
     *
     * Gana la versión CON entrega: es la que este plan conoció y la que dice la
     * verdad de esa jornada. La exigencia no se pierde — si la versión nueva
     * venía obligatoria del tipo, la fila superviviente lo hereda.
     *
     * @param  \Illuminate\Support\Collection<int, array>  $esperados
     * @return \Illuminate\Support\Collection<int, array>
     */
    protected function unaVersionPorDocumento(\Illuminate\Support\Collection $esperados): \Illuminate\Support\Collection
    {
        $porCodigo = $esperados->groupBy(fn ($item) => $item['template']->code);

        if ($porCodigo->every(fn ($grupo) => $grupo->count() === 1)) {
            return $esperados;
        }

        $conEntrega = $this->submissions()->pluck('form_template_id')->flip();

        return $porCodigo
            ->map(function ($grupo) use ($conEntrega) {
                if ($grupo->count() === 1) {
                    return $grupo->first();
                }

                $superviviente = $grupo->first(fn ($item) => $conEntrega->has($item['template']->id))
                    ?? $grupo->sortByDesc(fn ($item) => $item['template']->version)->first();

                $superviviente['is_required'] = $grupo->contains(fn ($i) => $i['is_required']);
                $superviviente['from_type_required'] = $grupo->contains(fn ($i) => $i['from_type_required'] ?? false);

                return $superviviente;
            })
            ->keyBy(fn ($item) => $item['template']->id);
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
