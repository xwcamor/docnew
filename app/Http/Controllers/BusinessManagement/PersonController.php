<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Person\BulkDeletePersonRequest;
use App\Http\Requests\BusinessManagement\Person\BulkRestorePersonRequest;
use App\Http\Requests\BusinessManagement\Person\BulkSetActivePersonRequest;
use App\Http\Requests\BusinessManagement\Person\DeletePersonRequest;
use App\Http\Requests\BusinessManagement\Person\EditAllUpdatePersonRequest;
use App\Http\Requests\BusinessManagement\Person\ForceDeletePersonRequest;
use App\Http\Requests\BusinessManagement\Person\ImportPersonRequest;
use App\Http\Requests\BusinessManagement\Person\StorePersonRequest;
use App\Http\Requests\BusinessManagement\Person\UpdatePersonRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\People\GeneratePeopleCsvJob;
use App\Jobs\BusinessManagement\People\GeneratePeopleExcelJob;
use App\Jobs\BusinessManagement\People\GeneratePeoplePdfJob;
use App\Jobs\BusinessManagement\People\GeneratePeopleWordJob;
use App\Models\AuditLog;
use App\Models\Person;
use App\Services\BusinessManagement\PersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    use \App\Http\Controllers\Concerns\TiposDeDocumento;

    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesGlobalRecords;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /** Pone el candado a la marca (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, Person $person): RedirectResponse
    {
        return $this->applyLock($person, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, Person $person): RedirectResponse
    {
        return $this->applyUnlock($person, $request);
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        // El orden que de verdad se aplico, no el que pidieron. Es lo que baja
        // a la pantalla para marcar la cabecera activa: devolviendo el crudo,
        // una vista guardada que cita una columna retirada dejaba la flecha
        // puesta en una cabecera que ya no ordenaba nada.
        $sortAplicado = Person::ordenValidoDelListado($request->get('sort'));
        $dirAplicada  = in_array($request->get('direction'), ['asc', 'desc'], true)
            ? $request->get('direction')
            : 'desc';

        $userId  = $request->user()?->id;
        $isSuper = $request->user()?->hasRole('super') ?? false;

        // people es per-tenant (BelongsToTenant lo scopea solo) — eager-load creator.
        // El super ve cross-tenant: carga el tenant para mostrarlo en el drawer.
        // El cargo y la empresa viajan al listado: es lo primero que se mira de
        // un trabajador («¿quién es el eléctrico de tal contratista?») y no
        // estaban, porque el cargo ni se migraba.
        $with = [
            'creator:id,name,email', 'country:id,name,iso_code',
            // Solo los roles VIGENTES. Un rol retirado se desactiva y no se
            // borra —una firma vieja apunta al rol con el que se firmó— así que
            // sin este filtro el listado seguía etiquetando de «Supervisor» a
            // quien ya no lo es.
            'roles' => fn ($q) => $q->select('id', 'person_id', 'role', 'is_active')->where('is_active', true),
            'companyLinks.company:id,name', 'companyLinks.position:id,code',
        ];
        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        // La cara y la firma en el propio listado, para quien pueda verlas. Es
        // como se revisa de un vistazo a quien le falta material despues de una
        // importacion: entrar ficha por ficha a 227 personas no es revisar.
        //
        // Se cargan ANTES de paginar para no disparar dos consultas por fila.
        $puedeVerMedia = $request->user()?->can('people.view_media') ?? false;

        if ($puedeVerMedia) {
            $with[] = 'currentPhoto';
            $with[] = 'currentSignature';
        }

        $people = Person::query()
            ->select('people.*')
            ->with($with)
            // En cuántas empresas trabaja y si tiene la cara enrolada: son las
            // dos cosas que se miran de una persona antes de mandarla a obra.
            ->withCount([
                'companyLinks',
                'biometrics as active_biometrics_count' => fn ($q) => $q->where('is_active', true),
                'signatures',
            ])
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        // `media` se cuelga de la fila y las relaciones se sueltan: si viajaran
        // enteras, el JSON llevaria el `file_path` de cada archivo, que es la
        // ruta real en disco y no tiene por que salir del servidor.
        if ($puedeVerMedia) {
            $people->getCollection()->transform(function (Person $persona) {
                $persona->setAttribute('media', $this->urlsDeMedia($persona));

                return $persona->unsetRelation('currentPhoto')->unsetRelation('currentSignature');
            });
        }

        $totalUnfiltered = Person::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('People/Index', [
            'people' => array_merge($people->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato — el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('people'),
            'filters' => [
                'name'         => array_values($names),
                'num_doc'      => $request->get('num_doc', ''),
                'doc_type'     => $request->get('doc_type', ''),
                'country_id'   => $request->get('country_id', []),
                'company_id'   => $request->get('company_id', []),
                'role'         => $request->get('role', []),
                'has_biometric'=> $request->filled('has_biometric')
                    ? filter_var($request->has_biometric, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'only_favorites' => $request->boolean('only_favorites'),
                'sort'         => $sortAplicado,
                'direction'    => $dirAplicada,
                'per_page'     => $perPage,
                // Filtros avanzados: array de clausulas {field, op, value}
                // que el drawer construye. Lo persisto para que al recargar
                // la pagina (paginate, sort) el filtro siga aplicado.
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'        => $isSuper,
            // Schema de campos filtrables — alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            ...$this->filterOptions(),
            'filterSchema'   => Person::filterSchema([
                'countries' => $this->countryOptions(),
                'doc_types' => $this->docTypeOptions(),
            ]),
        ]);
    }

    /** Los catálogos que necesitan los filtros del listado, y ninguno más. */
    protected function filterOptions(): array
    {
        return [
            'countryOptions' => $this->countryOptions(),
            'docTypeOptions' => $this->docTypeOptions(),
            'roleOptions'    => $this->roleOptions(),
            'companyOptions' => $this->companyOptions(),
            // Cual es la empresa del propio workspace. De eso depende que se le
            // pregunte a esta persona que aprueba: a un electricista de una
            // contratista no, porque quien autoriza un plan es del que contrata.
            'ownCompanyId'   => auth()->user()?->tenant?->company_id,
        ];
    }

    /** Los catálogos que necesita el formulario de alta y edición. */
    protected function formOptions(): array
    {
        return [
            'countryOptions' => $this->countryOptions(),
            'docTypeOptions' => $this->docTypeOptions(),
            // Los tipos de CADA país.
            //
            // El desplegable ofrecía solo los del país del usuario mientras el
            // campo de al lado dejaba elegir cualquier país: al dar de alta a
            // un chileno se elegía Chile y se dejaba «DNI» —que es peruano— y
            // el alta se caía con «tipo de documento no válido» sin ninguna
            // forma de arreglarlo desde la pantalla. Ahora la lista sigue al
            // país que se haya elegido.
            'docTypesByCountry' => $this->docTypesByCountry(),
            'roleOptions'       => $this->roleOptions(),
            'companyOptions'    => $this->companyOptions(),
            // Cual es la empresa del propio workspace: decide si a esta persona
            // se le pregunta que aprueba (ver `formOptions` de arriba).
            'ownCompanyId'      => auth()->user()?->tenant?->company_id,
            // El cargo: Técnico, Supervisor, Mecánico, Eléctrico. En el sistema
            // anterior `workers.position_id` es NOT NULL —los 372 trabajadores
            // traen cargo— y aquí el campo no existía ni en el formulario.
            'positionOptions' => \App\Models\Position::query()->where('is_active', true)->orderBy('code')
                ->get(['id', 'code'])
                ->map(fn ($p) => ['value' => $p->id, 'label' => $p->code])
                ->all(),
        ];
    }

    /** Empresas activas como Select options. */
    protected function companyOptions(): array
    {
        return \App\Models\Company::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
            ->all();
    }

    /** Países activos como Select options. */
    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
            ->all();
    }

    /**
     * Tipos de documento admitidos, **del catálogo**.
     *
     * Estaban escritos aquí —«no hay tabla para esto», decía este comentario—
     * y eso significaba que añadir el PTP, que en Perú llevan miles de
     * venezolanos, pasaba por tocar PHP y desplegar. Ahora es una fila.
     *
     * Si el catálogo estuviera vacío se cae a los tres de siempre, para que una
     * base sin sembrar no deje la pantalla sin poder dar de alta a nadie.
     */
    protected function docTypeOptions(): array
    {
        $tipos = \App\Models\DocumentType::delPais(auth()->user()?->country_id);

        if ($tipos->isEmpty()) {
            return array_map(fn ($t) => ['value' => $t, 'label' => $t], static::docTypesPorDefecto());
        }

        return $tipos->map(fn ($t) => ['value' => $t->code, 'label' => $t->label])->all();
    }

    /**
     * Los tres de Peru, para cuando el catalogo entero esta vacio.
     *
     * OJO con el alcance: es una red para una base SIN SEMBRAR, no para un pais
     * sin tipos. Se uso para lo segundo y el resultado era que eligiendo India
     * el formulario ofrecia un DNI —que es peruano— y la validacion lo daba por
     * bueno. Un pais sin tipos ahora lo dice; esto solo cubre el arranque en
     * seco.
     */
    public static function docTypesPorDefecto(): array
    {
        return ['DNI', 'CE', 'PASAPORTE'];
    }

    /**
     * Consulta un DNI en RENIEC para rellenar el nombre solo.
     *
     * La v1 la tenía y al portar el módulo se perdió, así que el nombre de cada
     * trabajador se teclea. Ese tecleo es el origen de la basura que hubo que
     * limpiar en la base maestra: el mismo DNI escrito de dos formas, un
     * celular en el campo del documento, un «11111111» inventado.
     *
     * Nunca estorba: si no hay token, si la API no contesta o si el DNI no
     * existe, devuelve el estado y la pantalla deja escribir a mano.
     */
    public function lookupDni(Request $request, \App\Services\Peru\ConsultaDni $reniec): \Illuminate\Http\JsonResponse
    {
        $validado = $request->validate([
            'dni' => ['required', 'string', 'max:20'],
        ]);

        return response()->json($reniec->buscar($validado['dni']));
    }

    /**
     * La foto de referencia o la firma guardada, servidas del disco privado.
     *
     * Nunca son públicas. Viven en `storage/app`, fuera de la carpeta que sirve
     * el servidor web, y salen por aquí: autenticado, con permiso y del propio
     * workspace (el scope de `Person` ya lo garantiza al resolver la ruta).
     */
    public function media(Request $request, Person $person, string $kind, \App\Services\FieldWork\SignatureService $firmas)
    {
        abort_unless(in_array($kind, ['photo', 'signature'], true), 404);

        $archivo = $kind === 'photo'
            ? $firmas->fotoVigente($person)
            : $firmas->firmaVigente($person);

        abort_if($archivo === null, 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($archivo->file_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($archivo->file_path);
    }

    /**
     * Sube la foto de referencia o la firma de una persona.
     *
     * La foto es la razón de ser de esto: la que se captura en obra sale a
     * contraluz, con casco y en movimiento, y con ella no se reconoce a nadie.
     * En el sistema anterior el administrador subía la buena a mano y aquí se
     * había perdido.
     *
     * No se sobrescribe: la anterior se cierra con `valid_to` y la nueva pasa a
     * ser la vigente, para que un plan firmado hace un año siga enseñando la
     * cara con la que se identificó entonces.
     */
    public function storeMedia(Request $request, Person $person, string $kind, \App\Services\FieldWork\SignatureService $firmas): RedirectResponse
    {
        abort_unless(in_array($kind, ['photo', 'signature'], true), 404);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ], [], ['file' => __('people.media_file')]);

        $binario = file_get_contents($request->file('file')->getRealPath());

        try {
            $kind === 'photo'
                ? $firmas->guardarFoto($person, $binario, \App\Models\PersonPhoto::SUBIDA)
                : $firmas->guardarFirma($person, base64_encode($binario));
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __($kind === 'photo' ? 'people.photo_saved' : 'people.signature_saved'));
    }

    /**
     * Que puede firmar esta persona en el flujo de aprobaciones.
     *
     * Sale del catalogo `approver_roles`, no de una lista escrita aqui. Eran
     * dos listas para lo mismo y podian separarse: el catalogo es una pantalla
     * y admite filas nuevas —la plantilla de reglas que se descarga ya trae un
     * «Jefe de Izaje»— pero el alta de una persona solo aceptaba tres codigos
     * clavados en PHP. Se podia crear la regla y **ninguna persona podia tener
     * nunca ese rol**: el plan quedaba con una firma que nadie iba a firmar y no
     * se cerraba jamas.
     *
     * Ya no esta «trabajador». Lo tendria el 100 % de las personas, asi que no
     * separaba nada, y lo que de verdad nombraba —quien responde por la
     * cuadrilla— resulto ser del plan y no de la persona: un electricista que va
     * solo a la obra es el representante ese dia y uno mas al siguiente. Vive en
     * `work_plans.crew_representative_person_id`.
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function roleOptions(): array
    {
        return \App\Models\ApproverRole::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['code', 'name_es', 'name_en'])
            // `label` ya elige el idioma (ver ApproverRole::getLabelAttribute).
            ->map(fn ($rol) => ['value' => $rol->code, 'label' => $rol->label])
            ->all();
    }

    /**
     * Normaliza `advanced_where` del request: viene como JSON string o
     * array directo segun como Inertia lo serialice. Filtra clausulas
     * vacias o incompletas antes de pasarlo al frontend.
     */
    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (!is_array($raw)) return [];

        return array_values(array_filter($raw, fn ($c) =>
            is_array($c) && !empty($c['field']) && !empty($c['op'])
        ));
    }

    public function show(Request $request, Person $person)
    {
        $person->load([
            'creator:id,name,email', 'deleter:id,name,email', 'locker:id,name',
            'country:id,name,iso_code',
            'roles', 'companyLinks.company:id,name', 'companyLinks.position:id,code',
            'currentPhoto', 'currentSignature',
        ])->loadCount([
            'companyLinks',
            'biometrics as active_biometrics_count' => fn ($q) => $q->where('is_active', true),
            'signatures',
        ]);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? $this->taparDocumentoEnHistorial(AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Person::class)
                    ->where('auditable_id', $person->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve())
            : [];

        return inertia('People/Show', [
            'person' => array_merge(
                $this->payload($person, withAudit: true),
                ['lock' => $this->lockMeta($person, $request)],
            ),
            'recordAudit'  => $this->recordAuditMeta($person),
            'activity'     => $activity,
        ]);
    }

    public function create()
    {
        return inertia('People/Form', [
            'person'           => null,
            'defaultCountryId' => request()->user()?->country_id,
            // Al crear se escribe el documento entero se tenga el permiso o
            // no: todavia no hay nada que tapar.
            'canViewPrivateInfo' => true,
            ...$this->formOptions(),
        ]);
    }

    public function store(StorePersonRequest $request, PersonService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant → no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Person::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.people.index')
            ->with('success', __('people.created'));
    }

    /**
     * Alta rápida de marca desde un select de otro módulo (ej. el form de
     * trafos) sin salir de la página. Misma validación que store() — incluye
     * unicidad insensible a acentos/mayúsculas, así que bloquea duplicados —
     * pero responde JSON con la marca creada para inyectarla en el select.
     * Gated por permission:people.create (super/admin pasan por sus permisos).
     */
    public function quickStore(StorePersonRequest $request, PersonService $service): \Illuminate\Http\JsonResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Person::count() >= $max) {
                return response()->json(['message' => __('plans.limit_records_reached', ['max' => $max])], 422);
            }
        }

        $person = $service->create($request->validated());

        return response()->json(['id' => $person->id, 'name' => $person->full_name], 201);
    }

    public function edit(Request $request, Person $person)
    {
        // Registro bloqueado (Lockable): ni se abre el formulario de edición.
        abort_if($person->is_locked, 403, __('locks.cannot_edit_locked'));

        // El vinculo con su empresa y su cargo: el formulario los edita, asi
        // que tienen que llegar cargados o saldrian siempre en blanco.
        $person->load(['country:id,name,iso_code', 'roles', 'companyLinks.position:id,code']);

        return inertia('People/Form', [
            'person'           => $this->payload($person),
            'defaultCountryId' => request()->user()?->country_id,
            // Quien no puede VER el documento tampoco puede reescribirlo.
            //
            // El formulario recibia el numero ya enmascarado —`******36`— en un
            // campo obligatorio y editable, asi que guardar cualquier cambio de
            // la persona (corregir un apellido, cambiarle la empresa) escribia
            // los asteriscos encima del DNI real. Ocho caracteres, unico, la
            // validacion lo daba por bueno y el trabajador se quedaba sin
            // documento sin que nadie viera un error. Ahora el campo sale
            // bloqueado, y el servidor lo repone igualmente (ver
            // UpdatePersonRequest) por si la peticion viene a mano.
            'canViewPrivateInfo' => \App\Support\PrivateInfo::visibleFor($request->user()),
            ...$this->formOptions(),
        ]);
    }


    public function update(UpdatePersonRequest $request, Person $person, PersonService $service): RedirectResponse
    {
        $service->update($person, $request->validated());

        return redirect()
            ->route('business_management.people.index')
            ->with('success', __('people.saved'));
    }

    public function delete(Person $person)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($person->is_locked, 403, __('locks.cannot_delete_locked'));

        // La confirmación muestra documento y empresas: hay homónimos.
        $person->load(['country:id,name,iso_code', 'companyLinks.company:id,name'])
               ->loadCount('companyLinks');

        return inertia('People/Delete', [
            'person' => $this->payload($person),
            // Lo que cuelga de la persona, dicho ANTES de pedir el motivo. La
            // pantalla ya traía el bloque escrito y nunca recibía nada: dar de
            // baja a alguien con cinco años de firmas avisaba exactamente igual
            // que dar de baja a alguien que se registró ayer.
            'dependents' => $person->countDependents(),
        ]);
    }

    public function deleteSave(DeletePersonRequest $request, Person $person, PersonService $service): RedirectResponse
    {
        // `Person::dependents()` marca firmas, aprobaciones y planes con
        // `block => true` desde el principio, y aqui no lo miraba nadie: se podia
        // dar de baja a alguien con cinco anos de firmas sin un solo aviso. Y
        // luego el borrado definitivo chocaba con la FK y salia un 500.
        if ($person->hasBlockingDependents()) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $service->delete($person, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$person->id]);

        return redirect()
            ->route('business_management.people.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$person->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['people.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds(60)->toIso8601String(),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => 60,
        ];
    }

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $name    = $request->get('name', '');
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        // En la papelera se busca por nombre, apellido o documento: es lo que
        // se recuerda de alguien que ya no está en el listado.
        $people = Person::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($name !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$name}%")
                ->orWhere('lastname', 'like', "%{$name}%")
                ->orWhere('num_doc', 'like', "%{$name}%")))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('People/Trash', [
            'people' => $people,
            'filters'   => [
                'name'     => $name,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, PersonService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = Person::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.people.trash')
            ->with('success', __('global.restored_success'));
    }

    /**
     * Edit All — pagina con tabla editable in-line de name + is_active.
     * El submit hace batch update en transaccion (editAllUpdate).
     */
    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $people = Person::query()
            ->filter($request)
            ->select('people.id', 'people.slug', 'people.name', 'people.lastname', 'people.doc_type', 'people.num_doc', 'people.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('People/EditAll', [
            'people' => $people,
            'filters'   => [
                'name'      => $request->get('name', ''),
                'is_active' => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'sort'      => $request->get('sort', 'id'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
        ]);
    }

    public function editAllUpdate(EditAllUpdatePersonRequest $request, PersonService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(Person::class, $ids);
        if (!empty($lockedIds)) {
            $lockedSet = array_flip($lockedIds);
            $changes = array_values(array_filter($changes, fn ($c) => !isset($lockedSet[(int) $c['id']])));
        }

        // Y los GLOBALES, por lo mismo: el guard salta dentro de la
        // transaccion y tumbaba el lote entero — el usuario acababa en el
        // dashboard sin mensaje y sin que se guardara nada de lo que escribio.
        [, $globalIds] = $this->splitGlobalIds(Person::class, $ids);
        if (!empty($globalIds)) {
            $globalSet = array_flip($globalIds);
            $changes = array_values(array_filter($changes, fn ($c) => !isset($globalSet[(int) $c['id']])));
        }

        $touched = $service->editAllUpdate($changes);

        $msg = __('global.updated_success') . " ({$touched})";
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
        }
        if (!empty($globalIds)) {
            $msg .= ' · ' . __('global.bulk_skipped_global', ['count' => count($globalIds)]);
        }

        return redirect()
            ->route('business_management.people.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el person. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, Person $person, PersonService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Person::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($person);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.people.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestorePersonRequest $request, PersonService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.people.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.people.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeletePersonRequest $request, $slug, PersonService $service): RedirectResponse
    {
        $model = Person::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        // Se confirma tipeando el documento: dos personas pueden llamarse igual.
        // Se compara con `safe_num_doc` —lo que la papelera enseña— y no con el
        // numero crudo: quien no tiene `people.view_private_info` ve la version
        // tapada, asi que exigirle el numero entero era pedirle que adivinara y
        // el boton no se activaba nunca.
        if (trim($data['name_confirmation']) !== $model->safe_num_doc) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.people.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    /**
     * El historial tambien enseña el documento, y ahi tampoco puede salir.
     *
     * `Auditable` guarda la fila entera en `old_values`/`new_values`, asi que
     * el alta de cada persona lleva su DNI completo dentro. La pestaña
     * «Historial» del super y del admin lo pintaba tal cual: la pantalla tapaba
     * el numero arriba y lo enseñaba entero cuatro centimetros mas abajo.
     *
     * Se tapa al salir, no al guardar: el registro de auditoria tiene que
     * seguir conservando lo que de verdad se escribio.
     *
     * @param  array<int, array<string, mixed>>  $entradas
     * @return array<int, array<string, mixed>>
     */
    protected function taparDocumentoEnHistorial(array $entradas): array
    {
        if (\App\Support\PrivateInfo::visibleFor(request()->user())) {
            return $entradas;
        }

        $tapar = function ($valores) {
            if (! is_array($valores) || ! filled($valores['num_doc'] ?? null)) {
                return $valores;
            }
            $valores['num_doc'] = \App\Support\PrivateInfo::enmascarar((string) $valores['num_doc']);

            return $valores;
        };

        return array_map(function (array $entrada) use ($tapar) {
            $entrada['old_values'] = $tapar($entrada['old_values'] ?? null);
            $entrada['new_values'] = $tapar($entrada['new_values'] ?? null);

            // El diff humanizado del Resource se calculo con el numero entero.
            foreach ($entrada['changes'] ?? [] as $i => $cambio) {
                if (($cambio['label'] ?? null) === __('people.num_doc')) {
                    foreach (['before', 'after'] as $lado) {
                        if (filled($cambio[$lado] ?? null) && $cambio[$lado] !== '—') {
                            $entrada['changes'][$i][$lado] =
                                \App\Support\PrivateInfo::enmascarar((string) $cambio[$lado]);
                        }
                    }
                }
            }

            return $entrada;
        }, $entradas);
    }

    protected function payload(Person $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'name'       => $m->name,
            'lastname'   => $m->lastname,
            'full_name'  => $m->full_name,
            'doc_type'   => $m->doc_type,
            // Enmascarado salvo `people.view_private_info`. El registro de
            // personas es donde estan todos los DNI juntos: si algo tiene que
            // ir tapado por defecto es precisamente esta pantalla.
            'num_doc'    => $m->safe_num_doc,
            'birthdate'  => $m->birthdate?->format('Y-m-d'),
            'country_id' => $m->country_id,
            'country'    => $m->relationLoaded('country') && $m->country
                ? ['id' => $m->country->id, 'name' => $m->country->name, 'iso_code' => $m->country->iso_code]
                : null,
            // Solo si es distinta del pais donde trabaja: es lo que hay que
            // mirar en la puerta, porque lleva carne de extranjeria y no DNI.
            'roles'      => $m->relationLoaded('roles')
                ? $m->roles->where('is_active', true)->pluck('role')->values()->all()
                : null,
            'companies'  => $m->relationLoaded('companyLinks')
                ? $m->companyLinks->map(fn ($l) => [
                    'company_id' => $l->company_id,
                    'name'       => $l->company?->name,
                    'position'   => $l->position?->code,
                    'is_active'  => (bool) $l->is_active,
                ])->values()->all()
                : null,
            // El vinculo que edita el formulario: empresa y cargo. Si la persona
            // trabaja en varias, el resto se ve en `companies` y se conserva.
            // Se prefiere el vinculo VIGENTE: quien dejo una contratista y esta
            // en otra tiene las dos filas, y el formulario abria en la vieja.
            'primary_link' => $m->relationLoaded('companyLinks') && $m->companyLinks->isNotEmpty()
                ? (function () use ($m) {
                    $l = $m->companyLinks->firstWhere('is_active', true) ?? $m->companyLinks->first();

                    return ['company_id' => $l->company_id, 'position_id' => $l->position_id];
                })()
                : null,
            'companies_count'  => $m->company_links_count,
            'has_biometric'    => $m->active_biometrics_count !== null ? $m->active_biometrics_count > 0 : null,
            'signatures_count' => $m->signatures_count,
            'is_active'  => $m->is_active,
            'tenant_id'  => $m->tenant_id,
            'is_locked'  => $m->is_locked,
            'lock_scope' => $m->lock_scope,
            'is_favorite' => (bool) ($m->is_favorite ?? false),
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
            'deleted_at' => $m->deleted_at,
        ];
        // La foto de referencia y la firma solo se nombran a quien puede
        // verlas. Y se manda la URL, nunca el archivo: el JSON de Inertia viaja
        // entero al navegador y una imagen incrustada ahi la lee cualquiera que
        // abra las herramientas del navegador.
        if ($withAudit && auth()->user()?->can('people.view_media')) {
            $base['media'] = $this->urlsDeMedia($m);
        }

        if ($withAudit) {
            $base['deleted_description'] = $m->deleted_description;
            $base['creator'] = $m->creator ? ['id' => $m->creator->id, 'name' => $m->creator->name, 'email' => $m->creator->email] : null;
            $base['deleter'] = $m->deleter ? ['id' => $m->deleter->id, 'name' => $m->deleter->name, 'email' => $m->deleter->email] : null;
        }
        return $base;
    }

    /**
     * Las URL de la foto y la firma vigentes de una persona.
     *
     * Se manda la URL, nunca el archivo: el JSON de Inertia viaja entero al
     * navegador y una imagen incrustada ahi la lee cualquiera que abra las
     * herramientas del navegador. La ruta que sirve el fichero exige
     * `people.view_media`, asi que la URL sola no abre nada.
     *
     * Vive aqui y no dentro de `payload()` porque la usan la ficha Y el
     * listado, y dos copias de esto acabarian ensenando cosas distintas.
     *
     * @return array{photo_url: ?string, photo_source: ?string, signature_url: ?string}
     */
    protected function urlsDeMedia(Person $m): array
    {
        $foto  = $m->relationLoaded('currentPhoto') ? $m->currentPhoto : $m->currentPhoto()->first();
        $firma = $m->relationLoaded('currentSignature') ? $m->currentSignature : $m->currentSignature()->first();

        return [
            'photo_url' => $foto ? route('business_management.people.media', [$m->slug, 'photo']) : null,
            // De donde salio la foto. Importa: una «capturada» es la que se
            // tomo en obra a falta de otra, y es justo la que hay que
            // reemplazar por una decente.
            'photo_source'  => $foto?->source,
            'signature_url' => $firma ? route('business_management.people.media', [$m->slug, 'signature']) : null,
        ];
    }

    // ── EXPORTS ─────────────────────────────────────────────────────────
    // Los 4 formatos van a queue como jobs async (mismo patron que Regions).
    // El job se encarga de la query con scope + render + Download record.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GeneratePeopleCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GeneratePeopleExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GeneratePeoplePdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GeneratePeopleWordJob::class);
    }

    /**
     * Helper comun de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Mismo patron que Region.
     */
    protected function dispatchExport(Request $request, string $format, string $jobClass): RedirectResponse
    {
        $options = $this->buildExportOptions($request, $format);
        $this->assertExportLimit($format, $options);
        $this->recordExportAudit($format, $options);
        $jobClass::dispatch(auth()->id(), $options);

        return back()->with('success', __('global.download_in_queue'));
    }

    /**
     * Valida que el dataset no exceda el limite del formato. Usuarios con
     * plan premium (feature flag `export_unlimited_rows`) saltean el limite.
     */
    protected function assertExportLimit(string $format, array $options): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limit = \App\Models\Setting::getExportLimit('people', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('people.export_limit_exceeded', [
                'count'  => number_format($count),
                'limit'  => number_format($limit),
                'format' => strtoupper($format),
            ]));
        }
    }

    /** Cuenta filas a exportar segun scope+filters. */
    protected function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return Person::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return Person::query()->filter($fakeReq)->count();
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\People\PeopleImportTemplate(),
            __('people.import_template_filename')
        );
    }

    public function import(ImportPersonRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearía people de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant específico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('people.import_super_blocked'),
            ], 422);
        }

        // El importador se construye DENTRO del try: su constructor consulta la
        // base (tipos de documento del pais) y si algo falla ahi, fuera del try
        // subia sin capturar y el usuario veia un 500 en vez del 422 con motivo.
        try {
            $importer = new \App\Imports\BusinessManagement\People\PeopleImport(
                mode:   $mode,
                dryRun: $dryRun,
            );

            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('PeopleImport failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => $this->humanizeImportError($e),
            ], 422);
        }

        return response()->json([
            'ok'      => true,
            'dry_run' => $dryRun,
            'summary' => $importer->summary(),
        ], 200);
    }

    /**
     * Convierte una excepcion de import en mensaje legible para el usuario.
     * El detalle tecnico queda en el log, no llega al cliente.
     */
    protected function humanizeImportError(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($e instanceof \Illuminate\Database\QueryException) {
            if (str_contains($msg, 'unique') || str_contains($msg, 'duplicate')) {
                return __('imports.err_unique_violation');
            }
            if (str_contains($msg, 'NOT NULL') || str_contains($msg, 'null value')) {
                return __('imports.err_not_null_violation');
            }
            if (str_contains($msg, 'foreign key') || str_contains($msg, 'violates foreign')) {
                return __('imports.err_foreign_key_violation');
            }
        }

        return __('imports.process_failed');
    }

    // ── BULK OPERATIONS ─────────────────────────────────────────────────
    public function bulkDelete(BulkDeletePersonRequest $request, PersonService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Person::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$allowedIds, $globalIds] = $this->splitGlobalIds(Person::class, $allowedIds);
        if (empty($allowedIds)) {
            return back()->with('error', __('global.bulk_skipped_global', ['count' => count($globalIds)]));
        }

        $result = $service->bulkDelete($allowedIds, $data['deleted_description']);

        if (!empty($result['queued'])) {
            // Async: el delete real ocurre despues del redirect; el undo
            // window de 60s no calza con un job que tarda minutos.
            return back()
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $deletedIds = $result['deleted'];
        $enUsoIds   = $result['in_use'] ?? [];

        // Ninguna se pudo borrar: es un aviso, no un exito con cero.
        if (empty($deletedIds)) {
            return back()->with('error', __('people.bulk_skipped_in_use', ['count' => count($enUsoIds)]));
        }

        $this->storeUndoableDelete($deletedIds);

        $msg = __('global.deleted_success') . ' (' . count($deletedIds) . ')';
        if (!empty($enUsoIds)) {
            $msg .= ' · ' . __('people.bulk_skipped_in_use', ['count' => count($enUsoIds)]);
        }
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
        }
        if (!empty($globalIds)) {
            $msg .= ' · ' . __('global.bulk_skipped_global', ['count' => count($globalIds)]);
        }

        return back()
            ->with('success', $msg)
            ->with('recentDelete', $this->buildRecentDeletePayload($deletedIds));
    }

    /**
     * Undo dentro del window de 60s. Validamos contra session claim:
     * quien borro puede deshacer su propio error sin permisos extra.
     * Defense in depth: el service solo restaura las filas con
     * deleted_by = current user.
     */
    public function undoLastDelete(Request $request, PersonService $service): RedirectResponse
    {
        $claim = session('people.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('people.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('people.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('people.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActivePersonRequest $request, PersonService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no cambian de estado en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Person::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$allowedIds, $globalIds] = $this->splitGlobalIds(Person::class, $allowedIds);
        if (empty($allowedIds)) {
            return back()->with('error', __('global.bulk_skipped_global', ['count' => count($globalIds)]));
        }

        $result = $service->bulkSetActive($allowedIds, (bool) $data['is_active']);

        if (!empty($result['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $msg = __('global.updated_success') . " ({$result['changed']})";
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
        }
        if (!empty($globalIds)) {
            $msg .= ' · ' . __('global.bulk_skipped_global', ['count' => count($globalIds)]);
        }

        return back()->with('success', $msg);
    }

    // ── Export helpers ──────────────────────────────────────────────────

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyeccion de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        // Sin 'id' (no se exporta). La columna `tenant` (workspace) SOLO es
        // exportable por super: el resto ve únicamente marcas de su propio
        // tenant. Gate de seguridad real (no basta ocultarla en el front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'name', 'lastname', 'doc_type', 'num_doc', 'country', 'roles',
            'companies', 'companies_count', 'has_biometric', 'is_active',
            $isSuper ? 'tenant' : null,
            'created_at', 'updated_at', 'creator',
        ]));

        // id y slug: identificadores internos, exportables SOLO por super.
        if ($request->user()?->hasRole('super')) {
            $allowedColumns = array_merge(['id', 'slug'], $allowedColumns);
        }

        $rules = [
            'scope'                   => 'nullable|in:filtered,selected,all',
            'selected_ids'            => 'array',
            'selected_ids.*'          => 'integer',
            'columns'                 => 'array|min:1',
            'columns.*'               => 'in:' . implode(',', $allowedColumns),
            'title'                   => 'nullable|string|max:120',
            'include_filters_summary' => 'boolean',
            'filters'                 => 'array',
        ];
        if ($format === 'pdf') {
            $rules['orientation'] = 'nullable|in:portrait,landscape';
            $rules['paper_size']  = 'nullable|in:a4,letter';
        }
        if ($format === 'excel') {
            $rules['autofilter']    = 'boolean';
            $rules['freeze_header'] = 'boolean';
        }

        $data = $request->validate($rules);

        return [
            'scope'                   => $data['scope']                   ?? 'filtered',
            'selected_ids'            => $data['selected_ids']            ?? [],
            'columns'                 => $data['columns']                 ?? $allowedColumns,
            'title'                   => $data['title']                   ?? __('people.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    /**
     * Escribe audit log manual del export. Event = 'export_queued' registra
     * la INTENCION del usuario; el estado final (ready/failed) vive en `downloads`.
     */
    protected function recordExportAudit(string $format, array $options): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => Person::class,
            'auditable_id'   => null,
            'module'         => 'people',
            'old_values'     => null,
            'new_values'     => [
                'format'                  => $format,
                'scope'                   => $options['scope']        ?? 'filtered',
                'columns'                 => $options['columns']      ?? [],
                'title'                   => $options['title']        ?? null,
                'orientation'             => $format === 'pdf'   ? ($options['orientation']    ?? null) : null,
                'paper_size'              => $format === 'pdf'   ? ($options['paper_size']     ?? null) : null,
                'autofilter'              => $format === 'excel' ? ($options['autofilter']     ?? null) : null,
                'freeze_header'           => $format === 'excel' ? ($options['freeze_header']  ?? null) : null,
                'include_filters_summary' => $options['include_filters_summary'] ?? false,
                'filters'                 => $options['filters']      ?? [],
                'selected_ids_count'      => count($options['selected_ids'] ?? []),
            ],
            'url'        => route('business_management.people.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
