<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\OilType\BulkDeleteOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\BulkRestoreOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\BulkSetActiveOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\DeleteOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\EditAllUpdateOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\ForceDeleteOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\ImportOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\StoreOilTypeRequest;
use App\Http\Requests\BusinessManagement\OilType\UpdateOilTypeRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\OilTypes\GenerateOilTypesCsvJob;
use App\Jobs\BusinessManagement\OilTypes\GenerateOilTypesExcelJob;
use App\Jobs\BusinessManagement\OilTypes\GenerateOilTypesPdfJob;
use App\Jobs\BusinessManagement\OilTypes\GenerateOilTypesWordJob;
use App\Models\AuditLog;
use App\Models\OilType;
use App\Services\BusinessManagement\OilTypeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OilTypeController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $userId  = $request->user()?->id;
        $isSuper = $request->user()?->hasRole('super') ?? false;

        // oil_types es catalogo global (sin tenant) — solo eager-load creator.
        $with = ['creator:id,name,email'];

        $oilTypes = OilType::query()
            ->select('oil_types.*')
            // ¿Tiene reglas de diagnóstico? = existe un cuadro de cromas para el
            // aceite, o umbrales fisicoquímicos para su código. 1/0 (portable
            // pgsql/sqlite) para que el listado muestre "Con/Sin reglas".
            ->selectRaw(
                '(case when exists(select 1 from rule_sets where rule_sets.oil_type_id = oil_types.id)'
                .' or exists(select 1 from fiqui_thresholds where fiqui_thresholds.oil_code = oil_types.code)'
                .' then 1 else 0 end) as has_rules'
            )
            ->with($with)
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        $totalUnfiltered = OilType::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('OilTypes/Index', [
            'oilTypes' => array_merge($oilTypes->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato â€” el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('oil_types'),
            'filters' => [
                'name'         => array_values($names),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
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
            // Schema de campos filtrables â€” alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            'filterSchema'   => OilType::filterSchema(),
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

    public function show(Request $request, OilType $oilType)
    {
        $oilType->load(['creator:id,name,email', 'deleter:id,name,email']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', OilType::class)
                    ->where('auditable_id', $oilType->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('OilTypes/Show', [
            'oilType' => $this->payload($oilType, withAudit: true) + ['has_rules' => $this->oilHasRules($oilType)],
            'recordAudit'  => $this->recordAuditMeta($oilType),
            'activity'     => $activity,
        ]);
    }

    public function create()
    {
        return inertia('OilTypes/Form', [
            'oilType'      => null,
            'cloneSources' => $this->cloneSources(),
        ]);
    }

    /**
     * Aceites que SÍ tienen reglas (cuadros de cromas o umbrales de fiquis) —
     * copiar de uno sin reglas no aportaría nada. Se ofrecen como origen para
     * clonar al crear un aceite nuevo, o al editar uno que aún no tiene reglas.
     * Se excluye opcionalmente el propio aceite (no tiene sentido copiarse a sí).
     */
    protected function cloneSources(?int $exceptId = null): array
    {
        return OilType::query()
            ->where('is_active', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(function ($q) {
                $q->whereExists(fn ($s) => $s->selectRaw('1')->from('rule_sets')
                        ->whereColumn('rule_sets.oil_type_id', 'oil_types.id'))
                  ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('fiqui_thresholds')
                        ->whereColumn('fiqui_thresholds.oil_code', 'oil_types.code'));
            })
            ->orderBy('name')->get(['id', 'name'])->all();
    }

    /** ¿Este aceite ya tiene reglas configuradas (cromas o fiquis)? */
    protected function oilHasRules(OilType $m): bool
    {
        return \App\Models\RuleSet::where('oil_type_id', $m->id)->exists()
            || ($m->code && \DB::table('fiqui_thresholds')->where('oil_code', $m->code)->exists());
    }

    public function store(StoreOilTypeRequest $request, OilTypeService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant â†’ no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && OilType::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $data = $request->validated();
        $cloneFrom = $data['clone_rules_from'] ?? null;
        unset($data['clone_rules_from']);

        $oilType = $service->create($data);

        // Escalable: si se eligió clonar reglas de otro aceite, se copian sus
        // cuadros de cromas + umbrales de fiquis → el aceite nuevo diagnostica
        // desde el inicio (no queda "Sin reglas"). Luego se ajustan.
        if ($cloneFrom) {
            $source = OilType::find($cloneFrom);
            if ($source) {
                app(\App\Services\Diagnostics\OilRuleCloner::class)->clone($source, $oilType);
            }
        }

        return redirect()
            ->route('business_management.oil_types.index')
            ->with('success', __('oil_types.created'));
    }

    public function edit(OilType $oilType)
    {
        $hasRules = $this->oilHasRules($oilType);

        return inertia('OilTypes/Form', [
            'oilType'      => $this->payload($oilType) + ['has_rules' => $hasRules],
            // Si el aceite aún no tiene reglas, ofrecer copiarlas de otro (mismo
            // criterio que al crear). Si ya tiene, no se ofrece (no se pisan).
            'cloneSources' => $hasRules ? [] : $this->cloneSources($oilType->id),
        ]);
    }


    public function update(UpdateOilTypeRequest $request, OilType $oilType, OilTypeService $service): RedirectResponse
    {
        $data = $request->validated();
        $cloneFrom = $data['clone_rules_from'] ?? null;
        unset($data['clone_rules_from']);

        $service->update($oilType, $data);

        // Si el aceite no tenía reglas y se eligió copiarlas de otro, se clonan
        // ahora (el cloner es idempotente: si ya tuviera reglas, no hace nada).
        if ($cloneFrom && !$this->oilHasRules($oilType)) {
            $source = OilType::find($cloneFrom);
            if ($source) {
                app(\App\Services\Diagnostics\OilRuleCloner::class)->clone($source, $oilType);
            }
        }

        return redirect()
            ->route('business_management.oil_types.index')
            ->with('success', __('oil_types.saved'));
    }

    public function delete(OilType $oilType)
    {
        return inertia('OilTypes/Delete', [
            'oilType' => $this->payload($oilType),
        ]);
    }

    public function deleteSave(DeleteOilTypeRequest $request, OilType $oilType, OilTypeService $service): RedirectResponse
    {
        $service->delete($oilType, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$oilType->id]);

        return redirect()
            ->route('business_management.oil_types.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$oilType->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['oil_types.recent_delete' => [
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

        $oilTypes = OilType::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%{$name}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('OilTypes/Trash', [
            'oilTypes' => $oilTypes,
            'filters'   => [
                'name'     => $name,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, OilTypeService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = OilType::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.oil_types.trash')
            ->with('success', __('global.restored_success'));
    }

    /**
     * Edit All â€” pagina con tabla editable in-line de name + is_active.
     * El submit hace batch update en transaccion (editAllUpdate).
     */
    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $oilTypes = OilType::query()
            ->filter($request)
            ->select('oil_types.id', 'oil_types.slug', 'oil_types.name', 'oil_types.code', 'oil_types.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('OilTypes/EditAll', [
            'oilTypes' => $oilTypes,
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

    public function editAllUpdate(EditAllUpdateOilTypeRequest $request, OilTypeService $service): RedirectResponse
    {
        $touched = $service->editAllUpdate($request->validated()['changes']);

        return redirect()
            ->route('business_management.oil_types.edit_all')
            ->with('success', __('global.updated_success') . " ({$touched})");
    }

    /**
     * Clona el oilType. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, OilType $oilType, OilTypeService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && OilType::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($oilType);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.oil_types.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreOilTypeRequest $request, OilTypeService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.oil_types.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.oil_types.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteOilTypeRequest $request, $slug, OilTypeService $service): RedirectResponse
    {
        $model = OilType::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.oil_types.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(OilType $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'name'       => $m->name,
            'code'       => $m->code,
            'sort_order' => $m->sort_order,
            'is_active'  => $m->is_active,
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
        return $this->dispatchExport($request, 'csv', GenerateOilTypesCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateOilTypesExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateOilTypesPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateOilTypesWordJob::class);
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

        $limit = \App\Models\Setting::getExportLimit('oil_types', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('oil_types.export_limit_exceeded', [
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
            return OilType::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return OilType::query()->filter($fakeReq)->count();
    }

    // â”€â”€ IMPORTS (two-phase: dry_run preview + commit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\OilTypes\OilTypesImportTemplate(),
            __('oil_types.import_template_filename')
        );
    }

    public function import(ImportOilTypeRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearÃ­a oilTypes de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant especÃ­fico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('oil_types.import_super_blocked', [], 'Super sin workspace asignado no puede importar â€” el match por nombre puede actualizar registros de otro tenant.'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\OilTypes\OilTypesImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('OilTypesImport failed', [
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
    public function bulkDelete(BulkDeleteOilTypeRequest $request, OilTypeService $service): RedirectResponse
    {
        $data   = $request->validated();
        $result = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if (!empty($result['queued'])) {
            // Async: el delete real ocurre despues del redirect; el undo
            // window de 60s no calza con un job que tarda minutos.
            return back()
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $deletedIds = $result['deleted'];
        $this->storeUndoableDelete($deletedIds);

        return back()
            ->with('success', __('global.deleted_success') . ' (' . count($deletedIds) . ')')
            ->with('recentDelete', $this->buildRecentDeletePayload($deletedIds));
    }

    /**
     * Undo dentro del window de 60s. Validamos contra session claim:
     * quien borro puede deshacer su propio error sin permisos extra.
     * Defense in depth: el service solo restaura las filas con
     * deleted_by = current user.
     */
    public function undoLastDelete(Request $request, OilTypeService $service): RedirectResponse
    {
        $claim = session('oil_types.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('oil_types.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('oil_types.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('oil_types.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveOilTypeRequest $request, OilTypeService $service): RedirectResponse
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if (!empty($result['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return back()->with('success', __('global.updated_success') . " ({$result['changed']})");
    }

    // â”€â”€ Export helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyeccion de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        $allowedColumns = ['name', 'code', 'sort_order', 'is_active', 'created_at', 'updated_at', 'creator'];

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
            'title'                   => $data['title']                   ?? __('oil_types.export_title'),
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
            'auditable_type' => OilType::class,
            'auditable_id'   => null,
            'module'         => 'oil_types',
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
            'url'        => route('business_management.oil_types.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
