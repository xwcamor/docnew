<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\FormTemplate\BulkDeleteFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\BulkRestoreFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\BulkSetActiveFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\DeleteFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\EditAllUpdateFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\ForceDeleteFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\ImportFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\StoreFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\UpdateFormTemplateRequest;
use App\Http\Requests\BusinessManagement\FormTemplate\UpdateFormTemplateStructureRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\FormTemplates\GenerateFormTemplatesCsvJob;
use App\Jobs\BusinessManagement\FormTemplates\GenerateFormTemplatesExcelJob;
use App\Jobs\BusinessManagement\FormTemplates\GenerateFormTemplatesPdfJob;
use App\Jobs\BusinessManagement\FormTemplates\GenerateFormTemplatesWordJob;
use App\Models\AuditLog;
use App\Models\FormField;
use App\Models\FormTemplate;
use App\Services\BusinessManagement\FormTemplateService;
use App\Services\BusinessManagement\FormTemplateStructureService;
use App\Services\FieldWork\FormTemplateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class FormTemplateController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesGlobalRecords;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /** Pone el candado al documento (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, FormTemplate $formTemplate): RedirectResponse
    {
        return $this->applyLock($formTemplate, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, FormTemplate $formTemplate): RedirectResponse
    {
        return $this->applyUnlock($formTemplate, $request);
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

        // form_templates es per-tenant (BelongsToTenant lo scopea solo) — eager-load creator.
        // El super ve cross-tenant: carga el tenant para mostrarlo en el drawer.
        $with = ['creator:id,name,email'];
        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        $form_templates = FormTemplate::query()
            ->select('form_templates.*')
            ->with($with)
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        $totalUnfiltered = FormTemplate::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('FormTemplates/Index', [
            'form_templates' => array_merge($form_templates->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato — el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('form_templates'),
            'filters' => [
                'name'         => array_values($names),
                'code'         => $request->get('code', ''),
                // Publicado / borrador: la pregunta real de este listado, y no
                // se podia ni filtrar ni ver.
                'status'       => $request->get('status', null),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                // Apagado (lo normal) = solo la version vigente de cada
                // documento. Cada version es una fila, y sin esto el listado
                // crece con cada edicion.
                'all_versions' => $request->boolean('all_versions'),
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
            // Schema de campos filtrables — alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            'filterSchema'   => FormTemplate::filterSchema(),
        ]);
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

    public function show(Request $request, FormTemplate $formTemplate)
    {
        $formTemplate->load(['creator:id,name,email', 'deleter:id,name,email', 'locker:id,name', 'country:id,name,iso_code']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', FormTemplate::class)
                    ->where('auditable_id', $formTemplate->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('FormTemplates/Show', [
            'formTemplate' => array_merge(
                $this->payload($formTemplate, withAudit: true),
                ['lock' => $this->lockMeta($formTemplate, $request)],
            ),
            'recordAudit'  => $this->recordAuditMeta($formTemplate),
            'activity'     => $activity,
        ]);
    }

    public function create(Request $request)
    {
        return inertia('FormTemplates/Form', [
            'formTemplate'     => null,
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function store(StoreFormTemplateRequest $request, FormTemplateService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant → no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && FormTemplate::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $formTemplate = $service->create($request->validated());

        // Al documento recien creado, no al listado. Un documento no esta
        // terminado cuando se guarda su cabecera: le faltan las secciones y los
        // campos, que es donde de verdad se define y lo que se hace en su
        // ficha. Devolver al indice obligaba a buscarlo entre los demas para
        // seguir con el paso siguiente.
        return redirect()
            ->route('business_management.form_templates.show', $formTemplate->slug)
            ->with('success', __('form_templates.created'));
    }

    /**
     * Alta rápida de un documento desde un select de otro módulo, sin salir de
     * la página. Misma validación que store() — incluye unicidad insensible a
     * acentos/mayúsculas, así que bloquea duplicados — pero responde JSON con
     * el documento creado para inyectarlo en el select.
     * Gated por permission:form_templates.create (super/admin pasan por sus permisos).
     */
    public function quickStore(StoreFormTemplateRequest $request, FormTemplateService $service): \Illuminate\Http\JsonResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && FormTemplate::count() >= $max) {
                return response()->json(['message' => __('plans.limit_records_reached', ['max' => $max])], 422);
            }
        }

        $formTemplate = $service->create($request->validated());

        return response()->json(['id' => $formTemplate->id, 'name' => $formTemplate->name], 201);
    }

    public function edit(FormTemplate $formTemplate)
    {
        // Registro bloqueado (Lockable): ni se abre el formulario de edición.
        abort_if($formTemplate->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('FormTemplates/Form', [
            'formTemplate'     => $this->payload($formTemplate),
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => $formTemplate->country_id,
        ]);
    }

    /**
     * Publicar un formato: lo que lo hace usable.
     *
     * Un formato nace en `draft` y la ficha del plan solo lista los publicados,
     * asi que **el que creabas desde la pantalla no podia aparecer nunca**. No
     * habia accion para publicarlo por ningun sitio: el unico camino era
     * `docufiz:migrate-formats`, o sea los cuatro que trajo la v1 y nada mas.
     * De ahi la sensacion de que no se podian añadir documentos a un plan.
     *
     * La comprobacion de que no salga vacio vive en el constructor, que es
     * quien sabe lo que es un formato bien formado.
     */
    public function publish(FormTemplate $formTemplate, \App\Services\FieldWork\FormTemplateBuilder $constructor): RedirectResponse
    {
        abort_if($formTemplate->is_locked, 403, __('locks.cannot_edit_locked'));

        try {
            $constructor->publicar($formTemplate);
        } catch (\InvalidArgumentException) {
            return back()->with('error', __('form_templates.publish_empty'));
        }

        return back()->with('success', __('form_templates.published'));
    }

    /**
     * Y despublicar, que no es borrar.
     *
     * Deja de ofrecerse en los planes nuevos; los que ya lo tienen conservan lo
     * que se lleno, porque la entrega guarda su version de la plantilla.
     */
    public function unpublish(FormTemplate $formTemplate, \App\Services\FieldWork\FormTemplateBuilder $constructor): RedirectResponse
    {
        abort_if($formTemplate->is_locked, 403, __('locks.cannot_edit_locked'));

        // Va por el constructor y no con un update suelto: publicar archiva la
        // version anterior y le quita las exigencias de los tipos de trabajo,
        // asi que despublicar tiene que devolverle las dos cosas. Con el update
        // suelto quedaba la anterior archivada y el pivote apuntando a un
        // borrador: tipos de trabajo exigiendo un documento inservible.
        $constructor->despublicar($formTemplate);

        return back()->with('success', __('form_templates.unpublished'));
    }

    // ── Secciones y campos ───────────────────────────────────────────────
    // Lo que faltaba para que un formato se pudiera definir sin tocar codigo.
    // El motor (`form_templates` → `form_sections` → `form_fields`) estaba
    // entero desde el principio y el unico camino para llenarlo era el comando
    // `docufiz:migrate-formats`: o sea, los cuatro que trajo la v1 y nada mas.

    /**
     * El editor de la estructura.
     *
     * Se abre siempre, tambien cuando no se puede tocar: quien entra a mirar
     * como esta hecho un AST publicado tiene derecho a verlo, y el motivo por
     * el que no se deja cambiar se dice en la pantalla en vez de cerrarle la
     * puerta con un 403 (docs/UI.md §6).
     */
    public function structure(FormTemplate $formTemplate, FormTemplateStructureService $estructura)
    {
        abort_if($formTemplate->is_locked, 403, __('locks.cannot_edit_locked'));

        $formTemplate->load(['sections.fields']);

        return inertia('FormTemplates/Structure', [
            'formTemplate' => [
                'id'      => $formTemplate->id,
                'slug'    => $formTemplate->slug,
                'name'    => $formTemplate->name,
                // El nombre en el idioma en curso, que es el que hay que leer
                // en la cabecera: `name` es el de por defecto y se queda para
                // los filtros y las pantallas ya escritas.
                'label'   => $formTemplate->label,
                'code'    => $formTemplate->code,
                'kind'    => $formTemplate->kind,
                'status'  => $formTemplate->status,
                'version' => $formTemplate->version,
            ],
            // El nombre de la seccion y la etiqueta del campo van en columnas
            // —`name_es`/`name_en`, `label_es`/`label_en`— y no en
            // `resources/lang`: un formato lo define el cliente desde aqui, y
            // lo que el escriba no puede vivir en el repositorio. Es el mismo
            // criterio de `approver_roles`.
            //
            // Al editor va UN nombre, el del idioma en el que se esta mirando, y
            // ahi mismo se guarda. Las dos columnas estan para que los cuatro
            // formatos que trae el producto vengan en los dos idiomas —eso lo
            // escribimos nosotros en el seeder—, no para que el cliente teclee
            // cada titulo dos veces.
            'sections' => $formTemplate->sections->map(fn ($s) => [
                'id'       => $s->id,
                'position' => $s->position,
                'name'     => $s->label,
                'fields'   => $s->fields->map(fn ($c) => [
                    'id'          => $c->id,
                    'code'        => $c->code,
                    // Los campos anteriores a la columna llevan su etiqueta en
                    // `config.label`, y el accesor ya cae ahi: abrir y guardar
                    // un formato viejo la deja en su sitio en vez de perderla.
                    'label'       => $c->label,
                    'field_type'  => $c->field_type,
                    'is_required' => (bool) $c->is_required,
                    'position'    => $c->position,
                    'config'      => $c->config ?? [],
                ])->values(),
            ])->values(),
            // La lista de tipos SALE DEL SERVIDOR, nunca escrita a mano en el
            // Vue: `FormField::TIPOS` es la unica lista de verdad y crece
            // segun se van portando formatos de la v1. Con una copia en el
            // front, un tipo nuevo existiria en la base y no en la pantalla.
            'fieldTypes'    => $this->catalogoDeTipos(),
            'editable'      => $estructura->esEditable($formTemplate),
            'lockedReason'  => $estructura->motivoBloqueo($formTemplate),
            'submissions'   => $estructura->entregas($formTemplate),
            'nextVersion'   => $formTemplate->version + 1,
        ]);
    }

    /**
     * Guardar: llega el arbol entero y se sincroniza de una vez.
     *
     * De una vez y no cambio a cambio, que es lo que hace el resto de los
     * formularios del producto. Aqui pesa mas todavia: mover un campo de sitio
     * son dos escrituras y media docena de posiciones renumeradas, y con
     * guardado incremental una tablet que pierde la señal a mitad deja el
     * documento con dos campos en la posicion 3.
     */
    public function structureUpdate(
        UpdateFormTemplateStructureRequest $request,
        FormTemplate $formTemplate,
        FormTemplateStructureService $estructura,
    ): RedirectResponse {
        try {
            $estructura->sincronizar($formTemplate, $request->validated()['sections']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('business_management.form_templates.structure', $formTemplate->slug)
            ->with('success', __('form_templates.structure_saved'));
    }

    /**
     * Version nueva a partir de una que ya es evidencia.
     *
     * Es la unica forma de cambiar un formato que ya se firmo: se copia la
     * estructura en un borrador con la version siguiente y el original no se
     * toca. `template_version` de la entrega existe justo para esto — cada
     * documento llenado recuerda con que version se lleno.
     *
     * Va con `form_templates.edit` y no con `.create` a proposito: para quien
     * usa la pantalla esto es *editar* un formato publicado, no dar de alta uno
     * distinto. El limite de registros del plan si se comprueba, porque una
     * fila nueva es una fila nueva.
     */
    public function newVersion(Request $request, FormTemplate $formTemplate, FormTemplateBuilder $constructor): RedirectResponse
    {
        abort_if($formTemplate->is_locked, 403, __('locks.cannot_edit_locked'));

        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && FormTemplate::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        try {
            $nueva = $constructor->nuevaVersion($formTemplate);
        } catch (\Throwable $e) {
            \Log::error('FormTemplate nuevaVersion failed', ['error' => $e->getMessage()]);

            return back()->with('error', __('form_templates.new_version_failed'));
        }

        return redirect()
            ->route('business_management.form_templates.structure', $nueva->slug)
            ->with('success', __('form_templates.new_version_created', ['version' => $nueva->version]));
    }

    /**
     * Claves de configuracion que cada tipo admite ADEMAS de las que exige.
     *
     * Las obligatorias las declara `FormTemplateBuilder::TIPOS`, que es quien
     * sabe que necesita cada tipo para poder pintarse. Estas son las que los
     * componentes de llenado leen si estan y se apañan si no: las respuestas
     * posibles de un checklist (EPP dice Conforme/No conforme, IHM dice
     * Cumple/No cumple), los catalogos de la matriz de riesgo, las columnas de
     * correccion que solo aparecen cuando algo sale no conforme.
     *
     * Sin esto el editor solo dejaria configurar el minimo, y un EPP sin sus
     * respuestas propias sale en la tablet con las de otro formato.
     */
    protected const CONFIG_OPCIONAL = [
        'person_checklist' => ['answers', 'extra'],
        'tool_checklist'   => ['tools', 'answers', 'extra'],
        'risk_matrix'      => ['activities', 'dangers', 'risks', 'controls'],
        'question_bank'    => ['answers'],
    ];

    /**
     * Los tipos de campo tal y como los pinta el editor.
     *
     * Se recorre `FormField::TIPOS` —la lista canonica— y para cada uno se
     * arma que se le puede configurar. Un tipo que aparezca ahi y que nadie
     * haya declarado en el constructor sale igual, con su etiqueta y su
     * obligatoriedad: se puede usar desde el primer minuto aunque todavia no
     * tenga configuracion propia.
     */
    protected function catalogoDeTipos(): array
    {
        $tipos = [];

        foreach (FormField::TIPOS as $tipo) {
            $obligatorias = FormTemplateBuilder::TIPOS[$tipo] ?? [];
            $exige        = in_array($tipo, FormTemplateBuilder::CONFIG_OBLIGATORIA, true);

            // La etiqueta del campo NO va aqui: vive en `label_es`/`label_en`,
            // que son columnas. Estuvo un rato en `config.label` porque no
            // habia donde ponerla, y un texto que se lee en pantalla dentro de
            // un JSON de configuracion no se puede ni filtrar ni traducir.
            $claves = array_values(array_unique(array_merge(
                $obligatorias,
                static::CONFIG_OPCIONAL[$tipo] ?? [],
            )));

            $tipos[] = [
                'value'  => $tipo,
                'label'  => $this->etiquetaDeTipo($tipo),
                'config' => array_map(fn ($clave) => [
                    'key'      => $clave,
                    'label'    => $this->etiquetaDeClave($clave),
                    'control'  => $this->controlDeClave($clave),
                    'required' => $exige && in_array($clave, $obligatorias, true),
                ], $claves),
            ];
        }

        return $tipos;
    }

    /** Nombre legible del tipo; si nadie lo tradujo todavia, su propia clave. */
    protected function etiquetaDeTipo(string $tipo): string
    {
        return Lang::has("form_templates.field_types.{$tipo}")
            ? __("form_templates.field_types.{$tipo}")
            : $tipo;
    }

    /** Igual para las claves de configuracion. */
    protected function etiquetaDeClave(string $clave): string
    {
        return Lang::has("form_templates.field_config_keys.{$clave}")
            ? __("form_templates.field_config_keys.{$clave}")
            : $clave;
    }

    /**
     * Con que control se edita cada clave de configuracion.
     *
     * Se deduce del nombre, no de una tabla, y por eso una clave nueva ya trae
     * su control puesto. El caso por defecto es la lista de textos porque
     * `catalogo()` —lo que usan los campos compuestos al pintarse— lee todas
     * las claves como listas: opciones, columnas, preguntas, herramientas.
     */
    protected function controlDeClave(string $clave): string
    {
        if (in_array($clave, ['min', 'max', 'decimals', 'max_files'], true)) {
            return 'number';
        }

        if (in_array($clave, ['label', 'help'], true)) {
            return 'text';
        }

        return 'list';
    }

    /** Paises activos como opciones del selector, igual que en los catalogos. */
    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
            ->all();
    }


    public function update(UpdateFormTemplateRequest $request, FormTemplate $formTemplate, FormTemplateService $service): RedirectResponse
    {
        $service->update($formTemplate, $request->validated());

        return redirect()
            ->route('business_management.form_templates.index')
            ->with('success', __('form_templates.saved'));
    }

    public function delete(FormTemplate $formTemplate)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($formTemplate->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('FormTemplates/Delete', [
            'formTemplate' => $this->payload($formTemplate),
            // Entregas, planes que lo piden y tipos que lo exigen. La pantalla
            // ya traía el bloque escrito y nunca recibía nada.
            'dependents'   => $formTemplate->countDependents(),
        ]);
    }

    public function deleteSave(DeleteFormTemplateRequest $request, FormTemplate $formTemplate, FormTemplateService $service): RedirectResponse
    {
        // `FormTemplate::dependents()` marca entregas y planes con `block => true`
        // y aqui no lo miraba nadie: se daba de baja un formato con entregas sin
        // un aviso, y el borrado definitivo posterior chocaba con la clave ajena
        // (`restrictOnDelete`) y devolvia un 500 en la cara del usuario.
        if ($formTemplate->hasBlockingDependents()) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $service->delete($formTemplate, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$formTemplate->id]);

        return redirect()
            ->route('business_management.form_templates.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$formTemplate->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['form_templates.recent_delete' => [
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

        $form_templates = FormTemplate::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%{$name}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('FormTemplates/Trash', [
            'form_templates' => $form_templates,
            'filters'   => [
                'name'     => $name,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, FormTemplateService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = FormTemplate::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.form_templates.trash')
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

        $form_templates = FormTemplate::query()
            ->filter($request)
            ->select('form_templates.id', 'form_templates.slug', 'form_templates.name', 'form_templates.code', 'form_templates.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('FormTemplates/EditAll', [
            'form_templates' => $form_templates,
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

    public function editAllUpdate(EditAllUpdateFormTemplateRequest $request, FormTemplateService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(FormTemplate::class, $ids);
        if (!empty($lockedIds)) {
            $lockedSet = array_flip($lockedIds);
            $changes = array_values(array_filter($changes, fn ($c) => !isset($lockedSet[(int) $c['id']])));
        }

        // Y los GLOBALES, por lo mismo: el guard salta dentro de la
        // transaccion y tumbaba el lote entero — el usuario acababa en el
        // dashboard sin mensaje y sin que se guardara nada de lo que escribio.
        [, $globalIds] = $this->splitGlobalIds(FormTemplate::class, $ids);
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
            ->route('business_management.form_templates.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el formTemplate. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, FormTemplate $formTemplate, FormTemplateService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && FormTemplate::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($formTemplate);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.form_templates.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreFormTemplateRequest $request, FormTemplateService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.form_templates.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.form_templates.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteFormTemplateRequest $request, $slug, FormTemplateService $service): RedirectResponse
    {
        $model = FormTemplate::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        // Se comprueba tambien aqui: una entrega puede haber aparecido despues de
        // mandarlo a la papelera, y entonces el borrado definitivo reventaba
        // contra la clave ajena en vez de decir por que no se puede.
        if ($model->hasBlockingDependents()) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.form_templates.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(FormTemplate $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'name'       => $m->name,
            'code'       => $m->code,
            // `sort_order` no existe en esta tabla: es un resto de haber
            // clonado el modulo de `Brand`, y la ficha enseñaba la version
            // siempre vacia. La version de una plantilla es `version`.
            'version'    => $m->version,
            'kind'       => $m->kind,
            // Sin el estado no se puede ni saber si el formato esta publicado,
            // que es lo que decide si un plan puede usarlo.
            'status'     => $m->status,
            'published_at' => $m->published_at,
            // El pais es obligatorio en la tabla y no llegaba a la ficha: se
            // pedia al crear y luego no se veia en ninguna parte. Un documento
            // pertenece a un pais y eso decide que planes pueden usarlo.
            'country_id'   => $m->country_id,
            'country_name' => $m->country?->name,
            'requires_signature' => (bool) $m->requires_signature,
            // Cuantos campos tiene definidos. Es lo que decide si «Publicar»
            // puede funcionar: un documento «con campos» y sin ninguno no se
            // publica, y el boton tiene que salir deshabilitado diciendolo, no
            // fallar al pulsarlo.
            'fields_count' => $m->fields()->count(),
            'is_active'  => $m->is_active,
            'tenant_id'  => $m->tenant_id,
            'is_locked'  => $m->is_locked,
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
        return $this->dispatchExport($request, 'csv', GenerateFormTemplatesCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateFormTemplatesExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateFormTemplatesPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateFormTemplatesWordJob::class);
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

        $limit = \App\Models\Setting::getExportLimit('form_templates', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('form_templates.export_limit_exceeded', [
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
            return FormTemplate::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return FormTemplate::query()->filter($fakeReq)->count();
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\FormTemplates\FormTemplatesImportTemplate(),
            __('form_templates.import_template_filename')
        );
    }

    public function import(ImportFormTemplateRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearía form_templates de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant específico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('form_templates.import_super_blocked', [], 'Super sin workspace asignado no puede importar — el match por nombre puede actualizar registros de otro tenant.'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\FormTemplates\FormTemplatesImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('FormTemplatesImport failed', [
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
    public function bulkDelete(BulkDeleteFormTemplateRequest $request, FormTemplateService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(FormTemplate::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$allowedIds, $globalIds] = $this->splitGlobalIds(FormTemplate::class, $allowedIds);
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
        $this->storeUndoableDelete($deletedIds);

        $msg = __('global.deleted_success') . ' (' . count($deletedIds) . ')';
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
    public function undoLastDelete(Request $request, FormTemplateService $service): RedirectResponse
    {
        $claim = session('form_templates.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('form_templates.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('form_templates.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('form_templates.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveFormTemplateRequest $request, FormTemplateService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no cambian de estado en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(FormTemplate::class, $data['ids']);
        if (empty($allowedIds)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($lockedIds)]));
        }

        // Y los GLOBALES: el guard de BelongsToTenantOrGlobal salta dentro
        // de la transaccion y hacia rollback del lote entero, con 403 al
        // dashboard y sin tocar ninguna de las que si se podian.
        [$allowedIds, $globalIds] = $this->splitGlobalIds(FormTemplate::class, $allowedIds);
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
        // exportable por super: el resto ve únicamente documentos de su propio
        // tenant. Gate de seguridad real (no basta ocultarla en el front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'name', 'code', 'kind', 'status', 'version', 'is_active',
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
            'title'                   => $data['title']                   ?? __('form_templates.export_title'),
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
            'auditable_type' => FormTemplate::class,
            'auditable_id'   => null,
            'module'         => 'form_templates',
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
            'url'        => route('business_management.form_templates.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
