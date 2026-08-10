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
 * Person — la identidad de quien trabaja en obra.
 *
 * En el sistema v1 una misma persona aparecía repetida una vez por cada empresa
 * en la que trabajaba. Aquí la identidad es única (documento + país) y lo que
 * cambia por empresa es el vínculo (`companyLinks`). La cara enrolada
 * (`activeBiometric`) y la firma de referencia también cuelgan de la persona,
 * no del vínculo. Es PER-TENANT, por eso usa BelongsToTenantOrGlobal.
 */
class Person extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenantOrGlobal, HasFavorites, \App\Traits\Lockable, \App\Traits\HasDependents;

    protected string $auditModule = 'people';


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

    /**
     * Lo que cuelga de la persona, dicho ANTES de pedir el motivo del borrado.
     *
     * La pantalla ya traía el bloque del aviso escrito —viene del molde— pero
     * el modelo no lo implementaba y el controlador no lo pasaba: dar de baja a
     * alguien con cinco años de firmas detrás avisaba exactamente igual que dar
     * de baja a alguien que se registró ayer.
     *
     * Las firmas y los planes BLOQUEAN: sus claves ajenas son
     * `restrictOnDelete` y el borrado definitivo ya reventaba con un 23503.
     *
     * Las aprobaciones bloquean por un motivo distinto y peor: su clave es
     * `nullOnDelete`, así que **no revienta** — borra en silencio quién aprobó
     * el plan y deja la aprobación sin dueño. Eso es justo lo que un documento
     * de seguridad tiene que conservar, y por eso es lo único de esta lista que
     * no se puede permitir aunque la base lo permita.
     */
    public function dependents(): array
    {
        return [
            'signatures' => [
                'model' => SignatureEvent::class,
                'fk'    => 'person_id',
                'label' => __('people.signatures_count'),
                'block' => true,
            ],
            'approvals' => [
                'model' => WorkPlanApproval::class,
                'fk'    => 'person_id',
                'label' => __('people.approvals_count'),
                'block' => true,
            ],
            'plans' => [
                'model' => WorkPlanPerson::class,
                'fk'    => 'person_id',
                'label' => __('people.plans_count'),
                'block' => true,
            ],
        ];
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
     * scopeFilter — mismo patrón que Customer, sobre la tabla people.
     * Soporta name (multi-tag accent-insensitive), code (substring),
     * is_active (bool), rangos de fecha/id, filtros avanzados y favoritos.
     */
    public function scopeFilter($query, $request)
    {
        $isPgsql = config('database.default') === 'pgsql';
        $tbl = 'people';

        // Se busca por nombre O apellido indistintamente: nadie recuerda en qué
        // orden se cargó "Juan Carlos Pérez Gómez".
        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql, $tbl) {
            $names = is_array($request->name) ? $request->name : [$request->name];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (empty($names)) return;
            $q->where(function ($qq) use ($names, $isPgsql, $tbl) {
                foreach ($names as $name) {
                    $needle = LikeQuery::contains((string) $name);
                    foreach (['name', 'lastname'] as $col) {
                        if ($isPgsql) {
                            $qq->orWhereRaw("unaccent(lower({$tbl}.{$col})) LIKE unaccent(lower(?))", [$needle]);
                        } else {
                            $qq->orWhereRaw("{$tbl}.{$col} LIKE ? ESCAPE '\\'", [$needle]);
                        }
                    }
                }
            });
        });

        $query->when($request->filled('num_doc'), function ($q) use ($request, $tbl) {
            $q->whereRaw(config('database.default') === 'pgsql' ? "{$tbl}.num_doc LIKE ?" : "{$tbl}.num_doc LIKE ? ESCAPE '\\'", [LikeQuery::contains((string) $request->num_doc)]);
        });

        $query->when($request->filled('doc_type'), function ($q) use ($request, $tbl) {
            $q->where("{$tbl}.doc_type", (string) $request->doc_type);
        });

        $query->when($request->filled('country_id'), function ($q) use ($request, $tbl) {
            $ids = array_filter((array) $request->input('country_id'), fn ($v) => $v !== '' && $v !== null);
            if (empty($ids)) return;
            $q->whereIn("{$tbl}.country_id", array_map('intval', $ids));
        });

        // Rol en obra: trabajador, supervisor o supervisor HSE.
        $query->when($request->filled('role'), function ($q) use ($request) {
            $roles = array_filter((array) $request->input('role'), fn ($v) => $v !== '' && $v !== null);
            if (empty($roles)) return;
            $q->whereHas('roles', fn ($r) => $r->whereIn('role', $roles)->where('is_active', true));
        });

        // Empresa en la que trabaja hoy (el vínculo, no la identidad).
        $query->when($request->filled('company_id'), function ($q) use ($request) {
            $ids = array_filter((array) $request->input('company_id'), fn ($v) => $v !== '' && $v !== null);
            if (empty($ids)) return;
            $q->whereHas('companyLinks', fn ($l) => $l->whereIn('company_id', array_map('intval', $ids)));
        });

        // Cara enrolada: sin biometría vigente la persona no puede firmar en obra.
        $query->when($request->filled('has_biometric'), function ($q) use ($request) {
            $tiene = filter_var($request->has_biometric, FILTER_VALIDATE_BOOLEAN);
            $existe = fn ($b) => $b->where('is_active', true);
            $tiene ? $q->whereHas('biometrics', $existe) : $q->whereDoesntHave('biometrics', $existe);
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

        $sort = static::ordenValidoDelListado($request->get('sort', 'id'));
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
        } elseif ($sort === 'document') {
            // La columna «Documento» de la tabla junta tipo y numero, asi que
            // no tiene columna propia que ordenar. Sin esto, pulsar la cabecera
            // (o elegirla en el desplegable de orden) no hacia absolutamente
            // nada: la peticion salia con `sort=document`, no encajaba en
            // ningun caso y la lista volvia igual.
            //
            // El tipo va primero y el numero despues porque es asi como se lee
            // la celda —«DNI 12345678»— y porque comparar un DNI con un
            // pasaporte por el numero no significa nada: son numeraciones de
            // dos sitios distintos. Agrupando por tipo, dentro de cada grupo el
            // numero si ordena, que es lo que se busca al repasar una lista de
            // documentos.
            $query->orderBy("{$tbl}.doc_type", $direction)
                  ->orderBy("{$tbl}.num_doc", $direction);
        } elseif ($sort === 'person' || $sort === 'lastname') {
            // La celda pone «APELLIDO, Nombre», asi que el orden tiene que leer
            // igual. Ordenando solo por apellido, los treinta Quispe que salen
            // seguidos quedaban en el orden en que se dieron de alta y parecia
            // que la cabecera no habia hecho nada.
            $query->orderBy("{$tbl}.lastname", $direction)
                  ->orderBy("{$tbl}.name", $direction);
        } elseif ($sort === 'company') {
            // La empresa cuelga del vinculo, no de la persona, y una persona
            // puede estar en varias: un left join duplicaria la fila y el
            // paginador contaria de mas. Por eso subconsulta escalar. Y ordena
            // por NOMBRE, no por `company_id`, porque con el id la lista sale
            // en el orden en que se dieron de alta las empresas, que para quien
            // mira es orden aleatorio.
            //
            // Se coge el nombre mas bajo alfabeticamente porque es justamente
            // el que la celda enseña primero: lo que se ordena es lo que se ve.
            $query->orderBy(
                \DB::table('person_company_links')
                    ->join('companies', 'companies.id', '=', 'person_company_links.company_id')
                    ->whereColumn('person_company_links.person_id', "{$tbl}.id")
                    ->whereNull('companies.deleted_at')
                    ->selectRaw('min(companies.name)'),
                $direction,
            );
        } elseif ($sort === 'position') {
            // Mismo caso que la empresa: el cargo vive en el vinculo. El
            // catalogo de cargos no tiene nombre aparte, el texto ES `code`
            // («Técnico», «Supervisor»), y es lo que se pinta.
            $query->orderBy(
                \DB::table('person_company_links')
                    ->join('positions', 'positions.id', '=', 'person_company_links.position_id')
                    ->whereColumn('person_company_links.person_id', "{$tbl}.id")
                    ->whereNull('positions.deleted_at')
                    ->selectRaw('min(positions.code)'),
                $direction,
            );
        } elseif (in_array($sort, ['companies_count', 'company_links_count'], true)) {
            $query->orderBy(static::subconsultaDeConteo('person_company_links', $tbl), $direction);
        } elseif (in_array($sort, ['biometric', 'active_biometrics_count'], true)) {
            // «Rostro» es una pastilla de si/no, pero por debajo es un conteo:
            // ordenarlo agrupa a quien no puede firmar en obra, que es
            // exactamente para lo que se pulsa esa cabecera.
            $query->orderBy(
                static::subconsultaDeConteo('person_biometrics', $tbl)->where('is_active', true),
                $direction,
            );
        } elseif ($sort === 'signatures_count') {
            $query->orderBy(static::subconsultaDeConteo('person_signatures', $tbl), $direction);
        } else {
            // Aqui solo llegan columnas reales de `people`: la lista blanca ya
            // descarto todo lo demas.
            $query->orderBy("{$tbl}.{$sort}", $direction);
        }

        return $query;
    }

    /**
     * Las claves de orden que el listado acepta, y ninguna mas.
     *
     * `sort` llega crudo de la barra de direcciones y acababa concatenado
     * dentro del ORDER BY, asi que la lista blanca no es una comodidad: es lo
     * que impide que alguien escriba SQL en la URL. Lo que no esta aqui cae al
     * orden por defecto —la fila mas nueva arriba— en vez de reventar la
     * pantalla, porque una vista guardada de hace meses puede citar una columna
     * que ya no existe y eso no puede dejar sin listado a nadie.
     *
     * @return array<int, string>
     */
    public static function ordenesDelListado(): array
    {
        return [
            // Columnas de `people`.
            'id', 'name', 'lastname', 'num_doc', 'doc_type', 'is_active', 'created_at', 'updated_at',
            // Columnas compuestas o de relacion, resueltas en `scopeFilter`.
            'person', 'document', 'country', 'tenant', 'company', 'position',
            // Conteos: la cabecera manda la clave de la columna y el
            // desplegable de orden manda el alias del withCount. Valen las dos.
            'companies_count', 'company_links_count',
            'biometric', 'active_biometrics_count',
            'signatures_count',
        ];
    }

    /** Normaliza el `sort` del request; lo que no este en la lista blanca cae a `id`. */
    public static function ordenValidoDelListado(mixed $sort): string
    {
        return (is_string($sort) && in_array($sort, static::ordenesDelListado(), true)) ? $sort : 'id';
    }

    /**
     * Conteo de filas hijas como subconsulta escalar, para ordenar por el.
     *
     * Se cuenta aqui en vez de reusar el alias del `withCount` del controlador
     * porque `scopeFilter` no lo pone el: lo pone quien lo llama. El listado si
     * lo trae, pero «Editar todo» y el contador del exportador llaman al mismo
     * scope con un SELECT recortado, y ahi un `order by signatures_count` se
     * referia a una columna que no existia en esa consulta.
     */
    protected static function subconsultaDeConteo(string $tablaHija, string $tbl): \Illuminate\Database\Query\Builder
    {
        return \DB::table($tablaHija)
            ->whereColumn("{$tablaHija}.person_id", "{$tbl}.id")
            ->selectRaw('count(*)');
    }

    /**
     * @return array<int, array{key: string, label: string, type: string, operators: array<int, string>}>
     */
    public static function filterSchema(array $opts = []): array
    {
        return [
            ['key' => 'name',       'label' => __('people.name'),      'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'lastname',   'label' => __('people.lastname'),  'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'num_doc',    'label' => __('people.num_doc'),   'type' => 'string',  'operators' => ['=', '!=', 'contains']],
            ['key' => 'doc_type',   'label' => __('people.doc_type'),  'type' => 'enum',    'operators' => ['=', '!='], 'options' => $opts['doc_types'] ?? []],
            ['key' => 'country_id', 'label' => __('people.country'),   'type' => 'enum',    'operators' => ['=', '!=', 'in'], 'options' => $opts['countries'] ?? []],
            ['key' => 'is_active',  'label' => __('people.is_active'), 'type' => 'boolean', 'operators' => ['=']],
            ['key' => 'created_at', 'label' => __('global.created_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
            ['key' => 'updated_at', 'label' => __('global.updated_at'),   'type' => 'date',    'operators' => ['>', '<', '>=', '<=']],
        ];
    }

    protected $fillable = [
        'slug', 'country_id', 'doc_type', 'num_doc',
        'name', 'lastname', 'birthdate', 'is_active', 'legacy_id', 'legacy_table',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = ['is_active' => 'boolean', 'birthdate' => 'date'];

    /**
     * El documento crudo NO viaja al navegador. Nunca.
     *
     * Enmascararlo en la plantilla no servia de nada: el listado mandaba la
     * persona entera en el JSON de Inertia —391 documentos completos en el
     * `<div id="app" data-page="...">` de la pagina— y bastaba abrir las
     * herramientas del navegador para leerlos. Lo mismo la papelera.
     *
     * Se tapa aqui, en la serializacion, porque es el unico sitio por el que
     * pasan todas las pantallas a la vez. Quien necesite el numero para pintar
     * lee `safe_num_doc`, que ya decide segun `people.view_private_info`; quien
     * lo necesite en PHP —buscar, comparar, exportar— sigue usando `num_doc`
     * como propiedad, que esto no lo toca.
     */
    protected $hidden = ['num_doc'];

    protected $appends = ['safe_num_doc'];

    public function getFullNameAttribute(): string
    {
        return trim($this->name . ' ' . $this->lastname);
    }

    /**
     * El apellido primero, que es como se lista a la gente en obra y como lo
     * hacia el sistema anterior (`Worker#str_complete_name_pro`).
     */
    public function getListNameAttribute(): string
    {
        return trim($this->lastname . ' ' . $this->name);
    }

    /**
     * El documento tal y como puede verlo quien esta mirando: entero si tiene
     * `people.view_private_info`, y si no `******78`.
     *
     * **Esto es lo que se manda al navegador.** `num_doc` a secas se queda en
     * el servidor: sirve para buscar y para comparar, no para pintar. La regla
     * viene del sistema anterior, donde el DNI solo salia completo con
     * `display_private_info`, y me la habia saltado.
     */
    public function getSafeNumDocAttribute(): ?string
    {
        return \App\Support\PrivateInfo::documento($this->num_doc);
    }

    public function country() { return $this->belongsTo(Country::class); }

    /**
     * De donde es.
     *
     * Apunta a `countries`, no a un catalogo aparte: una nacionalidad ES un
     * pais. Habia dos tablas para lo mismo y de ahi salio un susto — el tipo de
     * documento se deducia comparando el TEXTO de las dos, asi que con la fila
     * sembrada como «Peruana» los 224 peruanos habrian salido con carne de
     * extranjeria. Comparando numeros eso ya no puede pasar.
     */

    /**
     * Su nacionalidad, **solo si no es la del pais donde trabaja**.
     *
     * En el sistema anterior salia una banderita en las 391 filas de la
     * cuadrilla. Con el 97 % peruanos eso no informa: es la misma bandera
     * repetida 380 veces, y el ojo deja de verla. Lo que hay que ver es el que
     * viene de fuera —11 de 391— porque lleva carne de extranjeria en vez de
     * DNI, y eso es justo lo que se comprueba en la puerta.
     */
    /**
     * ¿Es extranjero donde trabaja? Lo que se comprueba en la puerta.
     *
     * Lo dice **su documento**, no una nacionalidad aparte: en Peru un peruano
     * lleva DNI y quien viene de fuera lleva carne de extranjeria, PTP o
     * pasaporte. El catalogo marca cual es cual (`document_types.for_foreigners`).
     *
     * Antes se comparaba una columna `nationality_id` con `country_id`, y esa
     * pregunta sobraba: el dato ya estaba en el tipo de documento. La
     * nacionalidad existia en la v1 porque alli NO habia tipo —de hecho el tipo
     * se dedujo de ella al migrar— y al portarla se quedaron las dos. La
     * columna se borro.
     *
     * Si el catalogo de ese pais no dice nada, no se inventa: se responde que
     * no es extranjero, que es lo que vale para el 97 % de la gente, y el
     * catalogo se siembra.
     */
    public function getIsForeignerAttribute(): bool
    {
        $tipo = DocumentType::query()
            ->where('country_id', $this->country_id)
            ->where('scope', DocumentType::PERSONA)
            ->where('code', $this->doc_type)
            ->first();

        return (bool) $tipo?->for_foreigners;
    }

    public function companyLinks() { return $this->hasMany(PersonCompanyLink::class); }
    public function companies() { return $this->belongsToMany(Company::class, 'person_company_links'); }
    public function roles() { return $this->hasMany(PersonRole::class); }
    public function biometrics() { return $this->hasMany(PersonBiometric::class); }
    public function signatures() { return $this->hasMany(PersonSignature::class); }
    public function photos() { return $this->hasMany(PersonPhoto::class); }
    public function signatureEvents() { return $this->hasMany(SignatureEvent::class); }

    /** Biometria vigente: es la que se compara al firmar. */
    public function activeBiometric()
    {
        return $this->hasOne(PersonBiometric::class)->where('is_active', true)->latestOfMany();
    }

    /** Firma de referencia vigente. */
    public function currentSignature()
    {
        return $this->hasOne(PersonSignature::class)->whereNull('valid_to')->latestOfMany();
    }

    /** Foto de referencia vigente: la cara con la que se le identifica. */
    public function currentPhoto()
    {
        return $this->hasOne(PersonPhoto::class)->whereNull('valid_to')->latestOfMany();
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('role', $role)->where('is_active', true)->exists();
    }

}
