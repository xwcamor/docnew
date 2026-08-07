<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\TapChangerBrand\BulkDeleteTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\BulkRestoreTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\BulkSetActiveTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\DeleteTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\EditAllUpdateTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\ForceDeleteTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\ImportTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\StoreTapChangerBrandRequest;
use App\Http\Requests\BusinessManagement\TapChangerBrand\UpdateTapChangerBrandRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\TapChangerBrands\GenerateTapChangerBrandsCsvJob;
use App\Jobs\BusinessManagement\TapChangerBrands\GenerateTapChangerBrandsExcelJob;
use App\Jobs\BusinessManagement\TapChangerBrands\GenerateTapChangerBrandsPdfJob;
use App\Jobs\BusinessManagement\TapChangerBrands\GenerateTapChangerBrandsWordJob;
use App\Models\AuditLog;
use App\Models\TapChangerBrand;
use App\Services\BusinessManagement\TapChangerBrandService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TapChangerBrandController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /** Pone el candado a la marca (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, TapChangerBrand $tapChangerBrand): RedirectResponse
    {
        return $this->applyLock($tapChangerBrand, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, TapChangerBrand $tapChangerBrand): RedirectResponse
    {
        return $this->applyUnlock($tapChangerBrand, $request);
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

        // tap_changer_brands es per-tenant + globales; el super ve cross-tenant, carga el tenant.
        $with = ['creator:id,name,email'];

        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        $tapChangerBrands = TapChangerBrand::query()
            ->select('tap_changer_brands.*')
            ->with($with)
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        $totalUnfiltered = TapChangerBrand::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('TapChangerBrands/Index', [
            'tapChangerBrands' => array_merge($tapChangerBrands->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato â€” el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('tap_changer_brands'),
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
            'filterSchema'   => TapChangerBrand::filterSchema(),
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

    public function show(Request $request, TapChangerBrand $tapChangerBrand)
    {
        $tapChangerBrand->load(['creator:id,name,email', 'deleter:id,name,email', 'locker:id,name']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', TapChangerBrand::class)
                    ->where('auditable_id', $tapChangerBrand->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('TapChangerBrands/Show', [
            'tapChangerBrand' => array_merge(
                $this->payload($tapChangerBrand, withAudit: true),
                ['lock' => $this->lockMeta($tapChangerBrand, $request)],
            ),
            'recordAudit'  => $this->recordAuditMeta($tapChangerBrand),
            'activity'     => $activity,
        ]);
    }

    public function create()
    {
        return inertia('TapChangerBrands/Form', [
            'tapChangerBrand'        => null,
        ]);
    }

    public function quickStore(StoreTapChangerBrandRequest $request, TapChangerBrandService $service): \Illuminate\Http\JsonResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && TapChangerBrand::count() >= $max) {
                return response()->json(['message' => __('plans.limit_records_reached', ['max' => $max])], 422);
            }
        }
        $record = $service->create($request->validated());
        return response()->json(['id' => $record->id, 'name' => $record->name], 201);
    }

    public function store(StoreTapChangerBrandRequest $request, TapChangerBrandService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant â†’ no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && TapChangerBrand::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.tap_changer_brands.index')
            ->with('success', __('tap_changer_brands.created'));
    }

    public function edit(TapChangerBrand $tapChangerBrand)
    {
        // Registro bloqueado (Lockable): ni se abre el formulario de edición.
        abort_if($tapChangerBrand->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('TapChangerBrands/Form', [
            'tapChangerBrand'        => $this->payload($tapChangerBrand),
        ]);
    }


    public function update(UpdateTapChangerBrandRequest $request, TapChangerBrand $tapChangerBrand, TapChangerBrandService $service): RedirectResponse
    {
        $service->update($tapChangerBrand, $request->validated());

        return redirect()
            ->route('business_management.tap_changer_brands.index')
            ->with('success', __('tap_changer_brands.saved'));
    }

    public function delete(TapChangerBrand $tapChangerBrand)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($tapChangerBrand->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('TapChangerBrands/Delete', [
            'tapChangerBrand' => $this->payload($tapChangerBrand),
        ]);
    }

    public function deleteSave(DeleteTapChangerBrandRequest $request, TapChangerBrand $tapChangerBrand, TapChangerBrandService $service): RedirectResponse
    {
        $service->delete($tapChangerBrand, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$tapChangerBrand->id]);

        return redirect()
            ->route('business_management.tap_changer_brands.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$tapChangerBrand->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['tap_changer_brands.recent_delete' => [
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

        $tapChangerBrands = TapChangerBrand::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%{$name}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('TapChangerBrands/Trash', [
            'tapChangerBrands' => $tapChangerBrands,
            'filters'   => [
                'name'     => $name,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, TapChangerBrandService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = TapChangerBrand::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.tap_changer_brands.trash')
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

        $tapChangerBrands = TapChangerBrand::query()
            ->filter($request)
            ->select('tap_changer_brands.id', 'tap_changer_brands.slug', 'tap_changer_brands.name', 'tap_changer_brands.code', 'tap_changer_brands.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('TapChangerBrands/EditAll', [
            'tapChangerBrands' => $tapChangerBrands,
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

    public function editAllUpdate(EditAllUpdateTapChangerBrandRequest $request, TapChangerBrandService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(TapChangerBrand::class, $ids);
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
            ->route('business_management.tap_changer_brands.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el tapChangerBrand. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, TapChangerBrand $tapChangerBrand, TapChangerBrandService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && TapChangerBrand::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($tapChangerBrand);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.tap_changer_brands.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreTapChangerBrandRequest $request, TapChangerBrandService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.tap_changer_brands.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.tap_changer_brands.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteTapChangerBrandRequest $request, $slug, TapChangerBrandService $service): RedirectResponse
    {
        $model = TapChangerBrand::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.tap_changer_brands.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(TapChangerBrand $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'name'       => $m->name,
            'code'       => $m->code,
            'sort_order' => $m->sort_order,
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

    // â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Los 4 formatos van a queue como jobs async (mismo patron que Regions).
    // El job se encarga de la query con scope + render + Download record.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateTapChangerBrandsCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateTapChangerBrandsExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateTapChangerBrandsPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateTapChangerBrandsWordJob::class);
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

        $limit = \App\Models\Setting::getExportLimit('tap_changer_brands', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('tap_changer_brands.export_limit_exceeded', [
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
            return TapChangerBrand::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return TapChangerBrand::query()->filter($fakeReq)->count();
    }

    // â”€â”€ IMPORTS (two-phase: dry_run preview + commit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\TapChangerBrands\TapChangerBrandsImportTemplate(),
            __('tap_changer_brands.import_template_filename')
        );
    }

    public function import(ImportTapChangerBrandRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearÃ­a tapChangerBrands de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant especÃ­fico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('tap_changer_brands.import_super_blocked', [], 'Super sin workspace asignado no puede importar â€” el match por nombre puede actualizar registros de otro tenant.'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\TapChangerBrands\TapChangerBrandsImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('TapChangerBrandsImport failed', [
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
    public function bulkDelete(BulkDeleteTapChangerBrandRequest $request, TapChangerBrandService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(TapChangerBrand::class, $data['ids']);
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
    public function undoLastDelete(Request $request, TapChangerBrandService $service): RedirectResponse
    {
        $claim = session('tap_changer_brands.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('tap_changer_brands.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('tap_changer_brands.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('tap_changer_brands.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveTapChangerBrandRequest $request, TapChangerBrandService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no cambian de estado en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(TapChangerBrand::class, $data['ids']);
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
            'title'                   => $data['title']                   ?? __('tap_changer_brands.export_title'),
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
            'auditable_type' => TapChangerBrand::class,
            'auditable_id'   => null,
            'module'         => 'tap_changer_brands',
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
            'url'        => route('business_management.tap_changer_brands.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
