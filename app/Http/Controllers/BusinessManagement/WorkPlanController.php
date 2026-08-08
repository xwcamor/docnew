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
use App\Services\BusinessManagement\WorkPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkPlanController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

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
            'workType:id,code',
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
            // Limites de export por formato â€” el frontend deshabilita formatos
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
            // Schema de campos filtrables â€” alimenta el drawer "Filtros
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
            'workTypeOptions'     => $opts(\App\Models\WorkType::query()->where('is_active', true), 'code'),
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
            'company:id,name,num_doc', 'workType:id,code', 'workLocation:id,name',
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
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        $user = $request->user();

        return inertia('WorkPlans/Show', [
            'workPlan' => array_merge(
                $this->payload($workPlan, withAudit: true),
                ['lock' => $this->lockMeta($workPlan, $request)],
            ),
            'recordAudit'  => $this->recordAuditMeta($workPlan),
            'activity'     => $activity,
            // La ficha del plan es el puesto de mando del supervisor: desde
            // aquí ve su cuadrilla, sus formatos y sus firmas, y entra a las
            // pantallas de obra. Todo se resuelve en esta consulta para no
            // dejar al frontend pidiendo datos de a poco.
            'crew'         => $this->crewPayload($workPlan),
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
    protected function crewPayload(WorkPlan $workPlan): array
    {
        $asignados = $workPlan->people()
            ->with([
                'person:id,slug,name,lastname,num_doc,doc_type',
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

        return $asignados
            ->map(function ($asignado) use ($firmas) {
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
                    'signed'    => (bool) $asignado->is_approved || $firmadoEn !== null,
                    'signed_at' => $firmadoEn,
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

        // El catálogo entero. Los que ya están en el plan salen de
        // `expectedFormTemplates()` —que sabe si los exige el tipo—; el resto se
        // añade apagado, para poder encenderlo.
        $todos = \App\Models\FormTemplate::query()
            ->where('status', 'published')
            ->orderBy('code')
            ->get()
            ->map(fn ($p) => $enElPlan->get($p->id) ?? [
                'template' => $p, 'is_required' => false,
                'source' => 'catalog', 'from_type_required' => false,
            ]);

        // Y los que están en el plan pero ya no en el catálogo (despublicados
        // después de usarse): no se esconden, que el documento existe.
        foreach ($enElPlan as $id => $item) {
            if (! $todos->contains(fn ($t) => $t['template']->id === $id)) {
                $todos->push($item);
            }
        }

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
                'signed_at' => $firmas->get($a->id),
            ])
            ->values()
            ->all();
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
        ];
    }

    public function create()
    {
        return inertia('WorkPlans/Form', [
            'workPlan' => null,
            ...$this->catalogOptions(),
        ]);
    }

    public function store(StoreWorkPlanRequest $request, WorkPlanService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant â†’ no aplica. -1 = ilimitado.
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
        $workPlan->load(['company:id,name,num_doc', 'workType:id,code', 'workLocation:id,name']);

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
     * Edit All â€” pagina con tabla editable in-line. En planes lo que se corrige
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
            'work_type'     => $m->relationLoaded('workType') && $m->workType ? ['id' => $m->workType->id, 'code' => $m->workType->code] : null,
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

    // â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
     * Helper comun de los 4 export endpoints: parse options â†’ limit check â†’
     * audit â†’ dispatch. Mismo patron que Region.
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

    // â”€â”€ IMPORTS (two-phase: dry_run preview + commit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
        // el lookup por nombre case-insensitive matchearÃ­a work_plans de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant especÃ­fico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('work_plans.import_super_blocked', [], 'Super sin workspace asignado no puede importar â€” el match por nombre puede actualizar registros de otro tenant.'),
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

    // â”€â”€ BULK OPERATIONS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

    // â”€â”€ Export helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

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
