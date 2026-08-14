<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\WorkPlan\BulkDeleteWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\BulkRestoreWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\BulkSetActiveWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\DeleteWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\EditAllUpdateWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\ForceDeleteWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\ImportWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\StoreWorkPlanRequest;
use App\Http\Requests\BusinessManagement\WorkPlan\UpdateWorkPlanRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\WorkPlans\GenerateWorkPlansCsvJob;
use App\Jobs\BusinessManagement\WorkPlans\GenerateWorkPlansExcelJob;
use App\Jobs\BusinessManagement\WorkPlans\GenerateWorkPlansPdfJob;
use App\Jobs\BusinessManagement\WorkPlans\GenerateWorkPlansWordJob;
use App\Models\AuditLog;
use App\Models\WorkPlan;
use App\Services\BusinessManagement\WorkPlanCompletionService;
use App\Services\BusinessManagement\WorkPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkPlanController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;
    use \App\Http\Controllers\Concerns\TiposDeDocumento;

    /** Pone el candado a la marca (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, WorkPlan $workPlan): RedirectResponse
    {
        return $this->applyLock($workPlan, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, WorkPlan $workPlan): RedirectResponse
    {
        return $this->applyUnlock($workPlan, $request);
    }

    /**
     * Reabre un plan terminado para poder corregirlo.
     *
     * No es el candado administrativo —ese ya no sale en la ficha del plan—:
     * esto devuelve el plan a «en curso» de verdad, y con el vuelven a estar
     * editables sus trabajadores, sus formatos, su representante y el propio
     * formulario. Queda anotado quien lo hizo y cuando.
     *
     * Va con `work_plans.edit`, el mismo permiso con el que se arma el plan:
     * quien puede montarlo puede corregirlo. Lo que no puede nadie es tocarlo
     * mientras esta terminado sin pasar por aqui, que es el punto.
     */
    public function reopen(WorkPlan $workPlan, WorkPlanCompletionService $cierre): RedirectResponse
    {
        $cierre->reabrir($workPlan);

        return back()->with('success', __('work_plans.reopened'));
    }

    /**
     * «Ya termine de corregir»: levanta la suspension y vuelve a evaluar.
     *
     * Si todavia falta algo no cierra y se dice que falta. La alternativa
     * —cerrar igual— seria dar por bueno un documento incompleto porque alguien
     * pulso un boton, que es justo lo que el cierre automatico existe para
     * evitar.
     */
    public function markDone(WorkPlan $workPlan, WorkPlanCompletionService $cierre): RedirectResponse
    {
        $cierre->darPorTerminado($workPlan);

        return back()->with('success', __('work_plans.marked_done'));
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $userId  = $request->user()?->id;
        $isSuper = $request->user()?->hasRole('super') ?? false;

        // El listado muestra empresa, tipo de trabajo y sede: sin eager-load
        // serían 3 queries por fila (y son 3.7k planes). Solo se traen las
        // columnas que la tabla pinta.
        $with = [
            'creator:id,name,email',
            'company:id,name,num_doc',
            // Con `name`: el tipo se enseña por su nombre (con caída al código
            // en las filas migradas que aún no lo tienen).
            'workType:id,code,name',
            'workLocation:id,name',
            'workstation:id,name',
            'workArea:id,name',
            'user:id,name,email',
        ];
        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        $work_plans = WorkPlan::query()
            ->select('work_plans.*')
            ->with($with)
            // Cuántos trabajadores tiene la cuadrilla y cuántos formatos se
            // levantaron: es lo que distingue un plan cargado de uno vacío.
            ->withCount(['people', 'submissions'])
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        $totalUnfiltered = WorkPlan::count();

        $search = $request->get('search', []);
        if (is_string($search)) $search = $search === '' ? [] : [$search];

        return inertia('WorkPlans/Index', [
            'work_plans' => array_merge($work_plans->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato — el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('work_plans'),
            'filters' => [
                'search'       => array_values($search),
                'company_id'       => $request->get('company_id', []),
                'work_type_id'     => $request->get('work_type_id', []),
                'work_location_id' => $request->get('work_location_id', []),
                'is_done'      => $request->filled('is_done')
                    ? filter_var($request->is_done, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'is_closed'    => $request->filled('is_closed')
                    ? filter_var($request->is_closed, FILTER_VALIDATE_BOOLEAN)
                    : null,
                // Los que se volvieron a abrir para corregir algo. Alimenta la
                // vista «Reabiertos» del listado.
                'reopened'     => $request->filled('reopened')
                    ? filter_var($request->reopened, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'date_from'    => $request->get('date_from', ''),
                'date_to'      => $request->get('date_to', ''),
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'only_favorites' => $request->boolean('only_favorites'),
                'sort'         => $request->get('sort', 'id'),
                'direction'    => $request->get('direction', 'desc'),
                'per_page'     => $perPage,
                // Filtros avanzados: array de clausulas {field, op, value}
                // que el drawer construye. Lo persisto para que al recargar
                // la pagina (paginate, sort) el filtro siga aplicado.
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'        => $isSuper,
            ...$this->catalogOptions(),
            // Schema de campos filtrables — alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            'filterSchema'   => WorkPlan::filterSchema($this->filterSchemaOptions()),
        ]);
    }

    /**
     * Catálogos de obra para los selectores del listado y del formulario.
     * Van juntos porque el formulario encadena sede → puesto y necesita las
     * dos listas de una sola vez.
     *
     * @return array<string, array<int, array{value: int, label: string}>>
     */
    protected function catalogOptions(): array
    {
        $opts = fn ($query, string $label) => $query
            ->orderBy($label)
            ->get(['id', $label])
            ->map(fn ($r) => ['value' => $r->id, 'label' => $r->{$label}])
            ->all();

        return [
            'companyOptions'      => \App\Models\Company::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'num_doc'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),
            // El selector enseña el nombre (con caída al código en las filas
            // migradas sin nombre); el value sigue siendo el id, así que los
            // filtros por work_type_id no cambian.
            'workTypeOptions'     => \App\Models\WorkType::query()->where('is_active', true)
                ->get(['id', 'code', 'name'])
                ->map(fn ($t) => ['value' => $t->id, 'label' => $t->label])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            'workLocationOptions' => $opts(\App\Models\WorkLocation::query()->where('is_active', true), 'name'),
            'workAreaOptions'     => $opts(\App\Models\WorkArea::query()->where('is_active', true), 'name'),
            // El puesto depende de la sede: el front filtra por work_location_id.
            'workstationOptions'  => \App\Models\Workstation::query()->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'work_location_id'])
                ->map(fn ($w) => ['value' => $w->id, 'label' => $w->name, 'work_location_id' => $w->work_location_id])
                ->all(),
        ];
    }

    /** Subconjunto de catálogos que consume el builder de filtros avanzados. */
    protected function filterSchemaOptions(): array
    {
        $o = $this->catalogOptions();

        return [
            'companies'      => $o['companyOptions'],
            'work_types'     => $o['workTypeOptions'],
            'work_locations' => $o['workLocationOptions'],
        ];
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

    public function show(Request $request, WorkPlan $workPlan)
    {
        $workPlan->load([
            'creator:id,name,email', 'deleter:id,name,email', 'locker:id,name',
            'company:id,name,num_doc', 'workType:id,code,name', 'workLocation:id,name',
            'workstation:id,name', 'workArea:id,name', 'user:id,name,email',
        ])->loadCount(['people', 'submissions']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', WorkPlan::class)
                    ->where('auditable_id', $workPlan->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        $user = $request->user();

        return inertia('WorkPlans/Show', [
            'workPlan' => array_merge(
                $this->payload($workPlan, withAudit: true),
                [
                    'lock'        => $this->lockMeta($workPlan, $request),
                    'reopened_at' => $workPlan->reopened_at,
                    'reopened_by' => $workPlan->reopenedBy?->name,
                ],
            ),
            'recordAudit'  => $this->recordAuditMeta($workPlan),
            'activity'     => $activity,
            // La ficha del plan es el puesto de mando del supervisor: desde
            // aquí ve su cuadrilla, sus formatos y sus firmas, y entra a las
            // pantallas de obra. Todo se resuelve en esta consulta para no
            // dejar al frontend pidiendo datos de a poco.
            'crew'         => $this->crewPayload($workPlan),
            // Quien responde por la cuadrilla. Va con la cuadrilla y no con las
            // aprobaciones porque no es una: no recoge firma propia, apunta a
            // alguien que ya firmo como trabajador de este plan.
            'representative' => $this->representativePayload($workPlan),
            'forms'        => $this->formsPayload($workPlan),
            'approvals'    => $this->approvalsPayload($workPlan),
            'setupOptions' => $this->setupOptions($workPlan),
            'setup'        => [
                // Se puede armar el plan: permiso + plan abierto. Los dos hacen
                // falta, y el motivo se muestra para no dejar botones muertos.
                'can'    => ($user?->can('work_plans.edit') ?? false) && $workPlan->isOpenForSetup(),
                'reason' => $workPlan->setupBlockedReason(),
            ],
            'fieldWork'    => [
                'canOpenForms' => $user?->can('form_submissions.view') ?? false,
                'canSign'      => $user?->can('form_submissions.sign') ?? false,
                'canExport'    => $user?->can('form_submissions.export') ?? false,
            ],
        ]);
    }

    /**
     * La cuadrilla con lo único que importa mirar antes de salir a obra: si la
     * persona tiene la cara enrolada (sin eso no puede firmar con
     * reconocimiento) y si ya firmó (y por tanto ya no se la puede quitar).
     */
    /**
     * La cara con la que firmo una persona en ESTE plan.
     *
     * Primero la que se capturo al firmar aqui —que es la prueba de que estuvo
     * ese dia— y, si no hay, la foto de referencia. Sin ninguna de las dos, 404.
     *
     * El permiso lo pone la ruta; que el plan sea del propio workspace lo
     * garantiza el scope al resolverlo, y que la persona este en la cuadrilla
     * se comprueba aqui: sin eso, con un slug cualquiera se sacaria la cara de
     * alguien que no pinta nada en este plan.
     */
    public function signerFace(WorkPlan $work_plan, \App\Models\Person $person, \App\Services\FieldWork\SignatureService $firmas)
    {
        // De la cuadrilla o del flujo: las dos son firmas de este plan.
        //
        // Antes solo miraba la cuadrilla, asi que un aprobador que no fuera
        // ademas trabajador —el supervisor autorizante, casi siempre— daba 404
        // y su fila se quedaba sin cara. La comprobacion sigue: con un slug
        // cualquiera no se saca la cara de quien no pinta nada en este plan.
        $asignado     = $work_plan->people()->where('person_id', $person->id)->first();
        $aprobaciones = $work_plan->approvals()->where('person_id', $person->id)->pluck('id');

        abort_if($asignado === null && $aprobaciones->isEmpty(), 404);

        $evidencia = \App\Models\EvidenceFile::query()
            ->whereIn('signature_event_id', \App\Models\SignatureEvent::query()
                ->where(fn ($q) => $q
                    ->when($asignado, fn ($w) => $w->orWhere(fn ($p) => $p
                        ->where('signable_type', (new \App\Models\WorkPlanPerson)->getMorphClass())
                        ->where('signable_id', $asignado->id)))
                    ->when($aprobaciones->isNotEmpty(), fn ($w) => $w->orWhere(fn ($p) => $p
                        ->where('signable_type', (new \App\Models\WorkPlanApproval)->getMorphClass())
                        ->whereIn('signable_id', $aprobaciones))))
                ->select('id'))
            ->where('kind', \App\Models\EvidenceFile::FACE)
            // Las filas que apuntan a `legacy/…` son un MARCADOR, no un
            // archivo: la importacion escribe ahi el nombre que la v1 tenia en
            // su columna, y el paso `archivos` lo cambia por la ruta real
            // cuando la foto aparece en la carpeta. Si nunca aparecio, la fila
            // se queda apuntando a un fichero que no existe.
            //
            // Sin esta condicion esa fila ganaba, la ruta no resolvia y la
            // ficha de la firma se abria con la imagen rota — teniendo la foto
            // buena de la persona ahi al lado, que es la que se enseña ahora.
            ->where('file_path', 'not like', 'legacy/%')
            ->latest('id')
            ->first();

        $ruta = $evidencia?->file_path ?? $firmas->fotoVigente($person)?->file_path;

        // Y si la evidencia consta en la base pero el fichero se perdio del
        // disco, tampoco se devuelve un 404 pudiendo enseñar la foto de la
        // ficha: la pregunta que contesta esta imagen —quien es esta persona—
        // la contesta igual de bien.
        if ($ruta !== null && ! \Illuminate\Support\Facades\Storage::disk('local')->exists($ruta)) {
            $ruta = $firmas->fotoVigente($person)?->file_path;
        }

        abort_if($ruta === null, 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($ruta), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($ruta);
    }

    /**
     * Quien ve LA FOTO de quien firmo en la ficha del plan.
     *
     * SOLO EL SUPER, por decision del dueño del producto: «la imagen solo la
     * ve el super». Son dos puertas y no una, y lo aclaro el mismo al ver la
     * primera version, que le quito al admin la ficha entera:
     *
     *   · la IMAGEN (`face_url` y la ruta `signer_face`, esa ademas con
     *     `role:super` en el middleware porque un payload en nulo no protege
     *     una URL adivinable) — solo super;
     *   · el RASTRO de la firma (`audit`: hora, coincidencia, IP, aparato,
     *     mapa) — sigue con `people.view_private_info`, como el DNI: el admin
     *     del workspace lo necesita para mirar una firma dudosa, y su ficha
     *     se abre con otro icono, sin foto dentro.
     */
    protected function puedeVerCaras(): bool
    {
        return request()->user()?->hasRole('super') ?? false;
    }

    protected function crewPayload(WorkPlan $workPlan): array
    {
        $asignados = $workPlan->people()
            ->with([
                'person:id,slug,name,lastname,num_doc,doc_type,country_id',
                'person.country:id,name',
                // El cargo de la persona EN LA EMPRESA DEL PLAN: la misma
                // persona puede ser técnico en una contratista y supervisor en
                // otra, y lo que se enseña es el de este trabajo.
                'person.companyLinks' => fn ($q) => $q->where('company_id', $workPlan->company_id)->with('position:id,code'),
            ])
            ->get();

        // Cuándo firmó cada uno, en UNA consulta. La v1 lo enseñaba en la ficha
        // («Firmado: 06-08-2026 09:14 p. m.») y se había perdido: aquí sólo
        // salía un sí/no, que en un documento de seguridad no vale — la hora es
        // parte de la prueba de que la persona estuvo antes de empezar.
        $firmas = \App\Models\SignatureEvent::query()
            ->where('signable_type', (new \App\Models\WorkPlanPerson)->getMorphClass())
            ->whereIn('signable_id', $asignados->pluck('id'))
            ->orderBy('signed_at')
            ->pluck('signed_at', 'signable_id');

        $comoFirmo = $this->comoSeFirmo(
            (new \App\Models\WorkPlanPerson)->getMorphClass(), $asignados->pluck('id'),
        );

        // La cara de quien firmo. Solo para el super —regla del dueño del
        // producto— y como URL, nunca como imagen incrustada: el JSON de
        // Inertia viaja entero al navegador.
        $puedeVerCaras = $this->puedeVerCaras();

        return $asignados
            ->map(function ($asignado) use ($firmas, $comoFirmo, $workPlan, $puedeVerCaras) {
                $firmadoEn = $firmas->get($asignado->id);

                return [
                    'slug'      => $asignado->slug,
                    'person'    => $asignado->person?->slug,
                    'name'      => $asignado->person?->list_name ?? '—',
                    // Enmascarado salvo permiso: el JSON de Inertia viaja entero
                    // al navegador, así que taparlo en la plantilla no tapa nada.
                    'num_doc'   => $asignado->person?->safe_num_doc,
                    'doc_type'  => $asignado->person?->doc_type,
                    // El cargo, que es lo que la v1 ponía debajo del nombre.
                    'position'  => $asignado->person?->companyLinks->first()?->position?->code,
                    // Y la nacionalidad.
                    //
                    // Aqui habia un comentario largo explicando que solo debia
                    // salir cuando NO fuera la del pais del trabajo —«una
                    // banderita peruana en 380 filas peruanas no informa de
                    // nada»— y la tarjeta leia `fila.foreign` para pintarla.
                    // El detalle: **el servidor no mandaba ese campo**, asi que
                    // la nacionalidad no salio nunca y `fila.foreign` llevaba
                    // desde el primer dia en `undefined`. Un comentario que
                    // describe una regla que no existe es peor que ninguno.
                    //
                    // Va entera y siempre, por decision del dueno del producto:
                    // en la ficha se comprueba quien entra a obra, y de si es
                    // del pais o no depende que documento lleva encima.
                    'nationality' => $asignado->person?->country?->name,
                    'signed'    => (bool) $asignado->is_approved || $firmadoEn !== null,
                    'signed_at' => $firmadoEn,
                    'signature' => $comoFirmo->get($asignado->id),
                    'face_url'  => $puedeVerCaras && $asignado->person
                        ? route('business_management.work_plans.signer_face', [$workPlan->slug, $asignado->person->slug])
                        : null,
                ];
            })
            ->all();
    }

    /**
     * **Todos** los formatos publicados, marcados o no.
     *
     * Así lo hacía el sistema anterior y es lo que yo no había entendido. Su
     * `_list_documents.html.erb` recorre `@documents` —el catálogo entero, no
     * los del tipo de trabajo— y pinta un interruptor por cada uno:
     *
     *     checked  = el tipo lo exige, o el plan ya lo tiene
     *     disabled = el tipo lo exige  → no se puede desmarcar
     *
     * Aquí había un desplegable de «formatos que quedan» y un botón Añadir. Con
     * cuatro formatos en el catálogo y los cuatro ya en el plan, el desplegable
     * salía vacío y parecía que el sistema no dejaba añadir nada. No era un
     * fallo del desplegable: es que **no hay nada que añadir**, porque todos los
     * formatos se ven siempre y lo que se hace es encenderlos y apagarlos.
     */
    protected function formsPayload(WorkPlan $workPlan): array
    {
        $entregas = $workPlan->submissions()
            ->withCount(['answers', 'attachments'])
            ->get()
            ->keyBy('form_template_id');

        $enElPlan = $workPlan->expectedFormTemplates();

        // PRIMERO los del plan, en el orden del tipo de trabajo (el que trae
        // `expectedFormTemplates()`); detrás, el resto del catálogo apagado,
        // por código, para poder encenderlo. Antes la lista entera se ordenaba
        // por código y los documentos del plan salían en un orden que no era
        // ni el del papel ni el del tipo de trabajo. Los del plan que ya no
        // estén en el catálogo (despublicados después de usarse) vienen dentro
        // de la primera mitad: el documento existe y no se esconde.
        $todos = collect($enElPlan->values())->concat(
            \App\Models\FormTemplate::query()
                ->where('status', 'published')
                ->orderBy('code')
                ->get()
                ->reject(fn ($p) => $enElPlan->has($p->id))
                ->map(fn ($p) => [
                    'template' => $p, 'is_required' => false,
                    'source' => 'catalog', 'from_type_required' => false,
                ]),
        );

        return $todos
            ->map(function ($item) use ($entregas, $enElPlan) {
                $plantilla = $item['template'];
                $entrega   = $entregas->get($plantilla->id);
                $conDatos  = $entrega && ($entrega->status === 'confirmed'
                    || $entrega->answers_count > 0 || $entrega->attachments_count > 0);

                $obligatorioDelTipo = (bool) ($item['from_type_required'] ?? false);

                return [
                    'slug'        => $plantilla->slug,
                    'code'        => $plantilla->code,
                    // El nombre de verdad: «AST (Análisis de Seguridad en el
                    // Trabajo)». El código es una sigla que hay que saberse.
                    'name'        => $plantilla->name ?: $plantilla->code,
                    'kind'        => $plantilla->kind,
                    // La versión que VALE para este plan: la congelada en la
                    // entrega si ya se abrió, y la vigente de la plantilla si
                    // todavía no. Sale pequeña en la fila — es lo que permite
                    // saber con qué revisión del documento se trabajó ese día
                    // sin abrir el PDF.
                    'version'     => $entrega?->template_version ?? $plantilla->version,
                    'included'    => $enElPlan->has($plantilla->id),
                    'required'    => $item['is_required'],
                    'source'      => $item['source'],
                    'status'      => $entrega->status ?? 'pending',
                    // Cuando se confirmó. Las otras dos columnas del tablero
                    // enseñan su hora y ésta no: la misma fila compartida las
                    // pinta igual, así que el dato tiene que llegar.
                    'confirmed_at' => $entrega?->submitted_at,
                    'submission'  => $entrega->slug ?? null,
                    // Lo exige el tipo de trabajo: el interruptor sale bloqueado,
                    // igual que el `disabled` del checkbox de la v1.
                    'locked_by_work_type' => $obligatorioDelTipo,
                    // Y un formato ya trabajado tampoco se apaga: eso borraría
                    // el documento de seguridad de ese día.
                    'can_toggle'  => ! $conDatos && ! $obligatorioDelTipo,
                    'has_content' => $conDatos,
                    // Cuántas cosas salieron mal. Es el entero `observations` de
                    // la v1, que era lo que el supervisor leía de un vistazo en
                    // la ficha: un EPP confirmado con tres arneses en mal estado
                    // no es lo mismo que uno confirmado y limpio.
                    'findings'    => (int) ($entrega->nonconformities ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    /** Quién tiene que aprobar el plan, si su firma es obligatoria y cuándo firmó. */
    /**
     * Como se produjo cada firma, indexado por el id del firmable.
     *
     * La hora dice CUANDO se firmo; esto dice si el servidor reconocio la cara
     * o no. Son cosas distintas y en la ficha solo se veia la primera: una
     * firma verificada y una que se capturo porque NO reconocio salian
     * exactamente iguales. Es un hecho que se enseña, no una tarea: la firma
     * vale igual y no hay ninguna revision pendiente detras.
     *
     * @return \Illuminate\Support\Collection<int, array{method:string, verified:bool, pending_review:bool}>
     */
    protected function comoSeFirmo(string $morph, iterable $ids): \Illuminate\Support\Collection
    {
        // El rastro completo con `people.view_private_info` —super y admin—
        // como el DNI: es lo que se mira ante una firma dudosa. La
        // FOTO no viaja por aqui; esa tiene su propia puerta, solo super.
        $puedeVerRastro = \App\Support\PrivateInfo::visibleFor(request()->user());

        return \App\Models\SignatureEvent::query()
            ->where('signable_type', $morph)
            ->whereIn('signable_id', collect($ids)->all())
            ->orderBy('signed_at')
            ->get([
                'signable_id', 'signed_at', 'method', 'used_ai', 'match_distance',
                'manual_override', 'override_reason', 'pending_review',
                'ip_address', 'user_agent', 'device_id', 'latitude', 'longitude',
            ])
            // La ultima gana: si alguien firmo dos veces, lo que cuenta es como
            // quedo, no como empezo.
            ->keyBy('signable_id')
            ->map(fn ($e) => [
                'method'         => $e->method,
                'used_ai'        => (bool) $e->used_ai,
                'verified'       => $e->isVerified(),
                'pending_review' => (bool) $e->pending_review,
                // Y el rastro, para la ficha que se abre al pulsar la cara. Va
                // aqui y no en otra consulta porque es la misma fila: quien
                // firmo, con que, desde donde y con que aparato son la misma
                // pregunta.
                'audit' => $puedeVerRastro ? [
                    'signed_at'       => $e->signed_at,
                    'match_percent'   => $e->match_percent,
                    'ip'              => $e->ip_address,
                    'user_agent'      => $e->user_agent,
                    'device_id'       => $e->device_id,
                    // Las coordenadas van en numero, no solo en texto: la ficha
                    // pinta un mapa con ellas. El texto se sigue enseñando
                    // debajo, que es lo que se copia y se pega en un informe.
                    'latitude'        => $e->latitude === null ? null : round((float) $e->latitude, 6),
                    'longitude'       => $e->longitude === null ? null : round((float) $e->longitude, 6),
                    'override_reason' => $e->override_reason,
                ] : null,
            ]);
    }

    protected function approvalsPayload(WorkPlan $workPlan): array
    {
        $aprobaciones = $workPlan->approvals()
            ->with(['person:id,slug,name,lastname,num_doc', 'approvalRule:id,name,approver_role,priority_level'])
            ->get();

        // La hora de cada aprobación, igual que en la cuadrilla: es la prueba
        // de cuándo se autorizó el trabajo, no un adorno.
        $firmas = \App\Models\SignatureEvent::query()
            ->where('signable_type', (new \App\Models\WorkPlanApproval)->getMorphClass())
            ->whereIn('signable_id', $aprobaciones->pluck('id'))
            ->orderBy('signed_at')
            ->pluck('signed_at', 'signable_id');

        // El ejecutante no firma aparte: vale la firma que ya dio como
        // trabajador de este plan, y de ahí sale la hora. Sin esto la fila
        // quedaría aprobada pero sin hora, que en las otras dos columnas sí se
        // enseña y aquí no — la misma información contada de dos maneras.
        $firmasDeCuadrilla = \App\Models\SignatureEvent::query()
            ->where('signable_type', (new \App\Models\WorkPlanPerson)->getMorphClass())
            ->whereIn('signable_id', $workPlan->people()->pluck('id'))
            ->join('work_plan_people as wpp', 'wpp.id', '=', 'signature_events.signable_id')
            ->orderBy('signature_events.signed_at')
            ->pluck('signature_events.signed_at', 'wpp.person_id');

        $comoFirmo = $this->comoSeFirmo(
            (new \App\Models\WorkPlanApproval)->getMorphClass(), $aprobaciones->pluck('id'),
        );

        $puedeVerCaras = $this->puedeVerCaras();

        return $aprobaciones
            ->sortBy(fn ($a) => $a->approvalRule?->priority_level ?? 99)
            ->map(fn ($a) => [
                'slug'      => $a->slug,
                'role'      => $a->approvalRule?->approver_role,
                // Como se llama esa firma en la obra: «Supervisor Autorizante -
                // HITACHI», no el rol generico. El rol dice que clase de persona
                // firma; el nombre dice por parte de quien.
                'rule_name' => $a->approvalRule?->name,
                'rule_id'   => $a->approval_rule_id,
                'person'    => $a->person ? ['slug' => $a->person->slug, 'name' => $a->person->list_name, 'num_doc' => $a->person->safe_num_doc] : null,
                'required'  => (bool) $a->is_required,
                'signed'    => (bool) $a->is_approved || $firmas->get($a->id) !== null,
                // La suya si la hay; si es el ejecutante, la que dio como
                // trabajador.
                'signed_at' => $firmas->get($a->id) ?? $firmasDeCuadrilla->get($a->person_id),
                'signature' => $comoFirmo->get($a->id),
                // La cara con la que firmó, igual que en la cuadrilla. Faltaba:
                // el aprobador es justo de quien más interesa saber que estuvo.
                'face_url'  => $puedeVerCaras && $a->person
                    ? route('business_management.work_plans.signer_face', [$workPlan->slug, $a->person->slug])
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * El representante de la cuadrilla, con la hora de la firma que le vale.
     *
     * `can_designate` es lo que decide si la tarjeta ofrece el boton: hace
     * falta alguien que ya haya firmado. Si la cuadrilla esta entera sin
     * firmar, no hay a quien designar todavia y la tarjeta lo dice en vez de
     * dejar un boton que solo puede fallar.
     */
    protected function representativePayload(WorkPlan $workPlan): array
    {
        $persona = $workPlan->crewRepresentative;

        $firmados = $workPlan->people()
            ->where(fn ($q) => $q->where('is_approved', true)->orWhereHas('signatureEvents'))
            ->count();

        return [
            'person' => $persona ? [
                'slug'     => $persona->slug,
                'name'     => $persona->list_name,
                'position' => $persona->companyLinks()
                    ->where('company_id', $workPlan->company_id)
                    ->with('position:id,code')
                    ->first()?->position?->code,
            ] : null,
            'can_designate' => $firmados > 0,
            'signed_crew'   => $firmados,
        ];
    }

    /**
     * Lo que se puede añadir: formatos publicados que el plan todavía no exige
     * y reglas de aprobación libres. Se calcula en el servidor porque depende
     * de lo que el plan ya tiene.
     */
    protected function setupOptions(WorkPlan $workPlan): array
    {
        $yaExigidos = $workPlan->expectedFormTemplates()->keys()->all();
        $yaAprueban = $workPlan->approvals()->pluck('approval_rule_id')->all();

        return [
            'formTemplates' => \App\Models\FormTemplate::query()
                ->published()
                ->whereNotIn('id', $yaExigidos)
                ->orderBy('code')
                ->get(['id', 'slug', 'code', 'kind'])
                ->map(fn ($t) => ['slug' => $t->slug, 'code' => $t->code, 'kind' => $t->kind])
                ->all(),
            'approvalRules' => \App\Models\ApprovalRule::query()
                ->where('is_active', true)
                ->where('country_id', $workPlan->country_id)
                ->whereNotIn('id', $yaAprueban)
                ->orderBy('priority_level')
                ->get(['id', 'approver_role', 'priority_level', 'is_required'])
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'role'        => $r->approver_role,
                    'is_required' => (bool) $r->is_required,
                ])
                ->all(),

            // Lo que hace falta para dar de alta a alguien sin salir de la
            // ficha: cargo, PAIS y tipo de documento. La empresa si sale del
            // plan — es la contratista que ejecuta.
            //
            // El pais se pregunta desde que el pais de la persona es su
            // nacionalidad («no estas poniendo el pais», dueño del producto):
            // un venezolano dado de alta desde la obra en Peru entra con
            // Venezuela y su cedula, no con el pais del plan. El del plan
            // queda preseleccionado, que es el caso comun.
            'positions' => \App\Models\Position::query()
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('country_id', $workPlan->country_id)->orWhereNull('country_id'))
                ->orderBy('code')
                ->get(['id', 'code'])
                ->map(fn ($p) => ['value' => $p->id, 'label' => $p->code])
                ->all(),
            'countries' => \App\Models\Country::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'iso_code'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
                ->all(),
            // Los tipos de CADA pais, para que la lista siga al pais elegido
            // en el propio modal — mismo arreglo que el formulario de
            // personas.
            'docTypesByCountry' => $this->docTypesByCountry(),
            'planCountryId' => $workPlan->country_id,
            // El minimo de caracteres del buscador de cuadrilla/aprobadores,
            // ya calculado para ESTE plan. Viaja en la carga inicial porque el
            // rotulo «escribe sus N digitos» se pinta antes de la primera
            // busqueda: sin esto arrancaba en el 7 heredado de la v1 y en una
            // empresa peruana mentia hasta que alguien tecleaba.
            'docMinimum' => $workPlan->minimoDocumento(),
        ];
    }

    public function create()
    {
        return inertia('WorkPlans/Form', [
            'workPlan' => null,
            ...$this->catalogOptions(),
            'descriptionSuggestions' => $this->descripcionesUsadas(),
        ]);
    }

    /**
     * Las descripciones de trabajo ya escritas, las más repetidas primero.
     *
     * El mismo aprendizaje que los textos libres del AST, pedido por el dueño
     * para este campo: en la v1 la descripción autocompletaba desde el
     * catálogo `jobs` (`search_jobs`), que alguien tenía que mantener. Aquí el
     * catálogo es lo que la gente ya escribió en otros planes — incluidos los
     * migrados, que traen dentro aquellos textos del catálogo viejo. Nada se
     * persiste: se calcula al abrir el formulario.
     *
     * El tope es generoso a proposito: la base migrada trae miles de planes
     * con descripciones que se repiten poco, y con 50 el buscador del campo
     * se quedaba ciego para todo lo que no fuera lo mas comun. 200 textos son
     * unas decenas de KB en la pagina: barato para lo que resuelve.
     *
     * @return array<int, string>
     */
    protected function descripcionesUsadas(int $tope = 200): array
    {
        return WorkPlan::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->selectRaw('description, count(*) as veces')
            ->groupBy('description')
            ->orderByDesc('veces')
            ->limit($tope)
            ->pluck('description')
            ->map(fn ($d) => trim((string) $d))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function store(StoreWorkPlanRequest $request, WorkPlanService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant → no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && WorkPlan::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $workPlan = $service->create($request->validated());

        // A la ficha, no al listado: el plan recién creado está vacío. Le
        // faltan los trabajadores y hay que asignar quién firma cada aprobación,
        // y eso se hace en la ficha. Mandar al listado obligaba a buscar el plan
        // que se acaba de crear para poder seguir trabajando en él.
        return redirect()
            ->route('business_management.work_plans.show', $workPlan)
            ->with('success', __('work_plans.created'));
    }

    /**
     * Alta rápida de marca desde un select de otro módulo (ej. el form de
     * trafos) sin salir de la página. Misma validación que store() — incluye
     * unicidad insensible a acentos/mayúsculas, así que bloquea duplicados —
     * pero responde JSON con la marca creada para inyectarla en el select.
     * Gated por permission:work_plans.create (super/admin pasan por sus permisos).
     */
    public function quickStore(StoreWorkPlanRequest $request, WorkPlanService $service): \Illuminate\Http\JsonResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && WorkPlan::count() >= $max) {
                return response()->json(['message' => __('plans.limit_records_reached', ['max' => $max])], 422);
            }
        }

        $workPlan = $service->create($request->validated());

        return response()->json(['id' => $workPlan->id, 'name' => $workPlan->code], 201);
    }

    public function edit(WorkPlan $workPlan)
    {
        // Dos cierres distintos, los dos valen. `is_locked` es el candado
        // administrativo del trait Lockable; `is_closed` es que el supervisor
        // dio el trabajo del día por terminado. Un plan cerrado ya es un
        // documento del archivo: se consulta, no se edita.
        abort_if($workPlan->is_locked, 403, __('locks.cannot_edit_locked'));
        abort_if($workPlan->is_closed, 403, __('work_plans.cannot_edit_closed'));

        return inertia('WorkPlans/Form', [
            'workPlan' => $this->payload($workPlan),
            ...$this->catalogOptions(),
            'descriptionSuggestions' => $this->descripcionesUsadas(),
        ]);
    }


    public function update(UpdateWorkPlanRequest $request, WorkPlan $workPlan, WorkPlanService $service): RedirectResponse
    {
        $service->update($workPlan, $request->validated());

        // A la ficha del plan que se acaba de editar, no al listado: quien
        // corrige la hora de fin o la descripción quiere ver el resultado, no
        // volver a buscar el plan entre 3 700.
        return redirect()
            ->route('business_management.work_plans.show', $workPlan)
            ->with('success', __('work_plans.saved'));
    }

    public function delete(WorkPlan $workPlan)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($workPlan->is_locked, 403, __('locks.cannot_delete_locked'));

        // La confirmación muestra empresa y trabajo: borrar un plan por su
        // código a secas es fácil de equivocar.
        $workPlan->load(['company:id,name,num_doc', 'workType:id,code,name', 'workLocation:id,name']);

        return inertia('WorkPlans/Delete', [
            'workPlan' => $this->payload($workPlan),
        ]);
    }

    public function deleteSave(DeleteWorkPlanRequest $request, WorkPlan $workPlan, WorkPlanService $service): RedirectResponse
    {
        $service->delete($workPlan, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$workPlan->id]);

        return redirect()
            ->route('business_management.work_plans.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$workPlan->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['work_plans.recent_delete' => [
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

        // En la papelera se busca por código de plan: es lo único que el
        // usuario recuerda de un plan que ya no está en el listado.
        $search  = $request->get('search', '');
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        $work_plans = WorkPlan::onlyTrashed()
            ->with(['deleter:id,name,email', 'company:id,name'])
            ->when($search !== '', fn ($q) => $q->where(fn ($qq) => $qq
                ->where('code', 'like', "%{$search}%")
                ->orWhere('num_os', 'like', "%{$search}%")))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('WorkPlans/Trash', [
            'work_plans' => $work_plans,
            'filters'   => [
                'search'   => $search,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, WorkPlanService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = WorkPlan::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.work_plans.trash')
            ->with('success', __('global.restored_success'));
    }

    /**
     * Edit All — pagina con tabla editable in-line. En planes lo que se corrige
     * en lote es la orden de servicio y el estado de avance; el código es
     * inmutable (identifica el plan) y por eso viaja solo como referencia.
     */
    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $work_plans = WorkPlan::query()
            ->filter($request)
            ->select('work_plans.id', 'work_plans.slug', 'work_plans.code', 'work_plans.num_os', 'work_plans.is_done')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('WorkPlans/EditAll', [
            'work_plans' => $work_plans,
            'filters'   => [
                'search'    => $request->get('search', ''),
                'is_done'   => $request->filled('is_done')
                    ? filter_var($request->is_done, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'sort'      => $request->get('sort', 'id'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
        ]);
    }

    public function editAllUpdate(EditAllUpdateWorkPlanRequest $request, WorkPlanService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(WorkPlan::class, $ids);
        if (!empty($lockedIds)) {
            $lockedSet = array_flip($lockedIds);
            $changes = array_values(array_filter($changes, fn ($c) => !isset($lockedSet[(int) $c['id']])));
        }

        $touched = $service->editAllUpdate($changes);

        $msg = __('global.updated_success') . " ({$touched})";
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
        }

        return redirect()
            ->route('business_management.work_plans.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el workPlan. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, WorkPlan $workPlan, WorkPlanService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && WorkPlan::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($workPlan);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        // A la ficha del clon: es un plan nuevo y vacío, igual que uno recién
        // creado, y lo siguiente es armarlo.
        return redirect()
            ->route('business_management.work_plans.show', $clone)
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreWorkPlanRequest $request, WorkPlanService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.work_plans.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.work_plans.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteWorkPlanRequest $request, $slug, WorkPlanService $service): RedirectResponse
    {
        $model = WorkPlan::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        // Se confirma tipeando el código del plan (no hay nombre que tipear).
        if (trim($data['name_confirmation']) !== $model->code) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.work_plans.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(WorkPlan $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'code'       => $m->code,
            'num_os'     => $m->num_os,
            'description'=> $m->description,
            'company_id'       => $m->company_id,
            'work_type_id'     => $m->work_type_id,
            'work_location_id' => $m->work_location_id,
            'workstation_id'   => $m->workstation_id,
            'work_area_id'     => $m->work_area_id,
            'country_id' => $m->country_id,
            'user_id'    => $m->user_id,
            // Nombres resueltos: la ficha los muestra tal cual, sin más queries.
            'company'       => $m->relationLoaded('company') && $m->company ? ['id' => $m->company->id, 'name' => $m->company->name, 'num_doc' => $m->company->num_doc] : null,
            // `label` es el nombre con caída al código: es lo que la ficha
            // pinta. El `code` se queda porque es la sigla y la identidad.
            'work_type'     => $m->relationLoaded('workType') && $m->workType ? ['id' => $m->workType->id, 'code' => $m->workType->code, 'label' => $m->workType->label] : null,
            'work_location' => $m->relationLoaded('workLocation') && $m->workLocation ? ['id' => $m->workLocation->id, 'name' => $m->workLocation->name] : null,
            'workstation'   => $m->relationLoaded('workstation') && $m->workstation ? ['id' => $m->workstation->id, 'name' => $m->workstation->name] : null,
            'work_area'     => $m->relationLoaded('workArea') && $m->workArea ? ['id' => $m->workArea->id, 'name' => $m->workArea->name] : null,
            'registered_by' => $m->relationLoaded('user') && $m->user ? ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email] : null,
            // Con hora: es «Fecha y hora de inicio», y de la diferencia sale el
            // tiempo trabajado. Sin zona — es la hora de la obra, no un instante UTC.
            'date_start'  => $m->date_start?->format('Y-m-d H:i'),
            'date_end'    => $m->date_end?->format('Y-m-d H:i'),
            'worked_time' => $m->worked_time,
            'worked_hours'=> $m->worked_hours,
            'is_done'    => (bool) $m->is_done,
            'people_count'      => $m->people_count,
            'submissions_count' => $m->submissions_count,
            'tenant_id'  => $m->tenant_id,
            // Los dos candados, que son distintos y los dos dejan el plan sin
            // editar. Van los dos porque el listado tiene que poder deshabilitar
            // Editar y Eliminar sin volver a preguntar:
            //   is_locked / locked_at → un administrador lo congeló a mano (Lockable).
            //   is_closed             → el plan terminó y es documento de archivo.
            'is_locked'  => $m->is_locked,
            'locked_at'  => $m->locked_at,
            'is_closed'  => (bool) $m->is_closed,
            // Reabierto: en curso, pero no porque le falte algo — porque
            // alguien lo abrio para corregirlo. El listado lo distingue del
            // pendiente normal, que si no un plan que ya estuvo terminado se
            // pierde entre los del dia.
            'reopened_at' => $m->reopened_at,
            'lock_scope' => $m->lock_scope,
            'is_favorite' => (bool) ($m->is_favorite ?? false),
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
            'deleted_at' => $m->deleted_at,
        ];
        if ($withAudit) {
            $base['deleted_description'] = $m->deleted_description;
            $base['creator'] = $m->creator ? ['id' => $m->creator->id, 'name' => $m->creator->name, 'email' => $m->creator->email] : null;
            $base['deleter'] = $m->deleter ? ['id' => $m->deleter->id, 'name' => $m->deleter->name, 'email' => $m->deleter->email] : null;
        }
        return $base;
    }

    // ── EXPORTS ─────────────────────────────────────────────────────────
    // Los 4 formatos van a queue como jobs async (mismo patron que Regions).
    // El job se encarga de la query con scope + render + Download record.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateWorkPlansCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateWorkPlansExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateWorkPlansPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateWorkPlansWordJob::class);
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

        $limit = \App\Models\Setting::getExportLimit('work_plans', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('work_plans.export_limit_exceeded', [
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
            return WorkPlan::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return WorkPlan::query()->filter($fakeReq)->count();
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\WorkPlans\WorkPlansImportTemplate(),
            __('work_plans.import_template_filename')
        );
    }

    public function import(ImportWorkPlanRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearía work_plans de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant específico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('work_plans.import_super_blocked', [], 'Super sin workspace asignado no puede importar — el match por nombre puede actualizar registros de otro tenant.'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\WorkPlans\WorkPlansImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('WorkPlansImport failed', [
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
    public function bulkDelete(BulkDeleteWorkPlanRequest $request, WorkPlanService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(WorkPlan::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        $result = $service->bulkDelete($allowedIds, $data['deleted_description']);

        if (!empty($result['queued'])) {
            // Async: el delete real ocurre despues del redirect; el undo
            // window de 60s no calza con un job que tarda minutos.
            return back()
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $deletedIds = $result['deleted'];
        $this->storeUndoableDelete($deletedIds);

        $msg = __('global.deleted_success') . ' (' . count($deletedIds) . ')';
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
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
    public function undoLastDelete(Request $request, WorkPlanService $service): RedirectResponse
    {
        $claim = session('work_plans.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('work_plans.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('work_plans.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('work_plans.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveWorkPlanRequest $request, WorkPlanService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no cambian de estado en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(WorkPlan::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        $result = $service->bulkSetActive($allowedIds, (bool) $data['is_active']);

        if (!empty($result['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $msg = __('global.updated_success') . " ({$result['changed']})";
        if (!empty($lockedIds)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]);
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
        // exportable por super: el resto ve únicamente planes de su propio
        // tenant. Gate de seguridad real (no basta ocultarla en el front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'code', 'num_os', 'description', 'company', 'work_type', 'work_location',
            'workstation', 'work_area', 'date_start', 'date_end', 'is_done', 'is_locked',
            'people_count', 'registered_by',
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
            'title'                   => $data['title']                   ?? __('work_plans.export_title'),
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
            'auditable_type' => WorkPlan::class,
            'auditable_id'   => null,
            'module'         => 'work_plans',
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
            'url'        => route('business_management.work_plans.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
