<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Models\SystemModule;
use App\Http\Requests\SystemManagement\SystemModule\StoreRequest;
use App\Http\Requests\SystemManagement\SystemModule\UpdateRequest;
use App\Http\Requests\SystemManagement\SystemModule\DeleteRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Requests\SystemManagement\SystemModule\BulkDeleteRequest;
use App\Http\Requests\SystemManagement\SystemModule\BulkSetActiveRequest;
use App\Http\Requests\SystemManagement\SystemModule\BulkRestoreRequest;
use App\Http\Requests\SystemManagement\SystemModule\ForceDeleteRequest;
use App\Http\Requests\SystemManagement\SystemModule\EditAllUpdateRequest;
use App\Http\Requests\SystemManagement\SystemModule\ImportRequest;
use App\Services\SystemManagement\SystemModuleService;
use Illuminate\Http\Request;

/**
 * Permisos: todas las rutas viven dentro de `role:super` middleware en
 * routes/system_management.php. Por eso NO chequeamos permission:system_modules.*
 * por método — sería redundante. Al clonar a módulos per-tenant, mover las
 * rutas fuera de ese grupo y aplicar `permission:patients.edit` etc.
 *
 * Acciones críticas (force_delete, bulk_restore, restore, undo_last_delete)
 * tienen su propio abort_unless(super) como defense-in-depth.
 */
class SystemModuleController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $userId = $request->user()?->id;

        // orderByFavoriteFirst hace LEFT JOIN a user_favorites y expone
        // is_favorite como columna calculada en la misma query (no N+1).
        $system_modules = SystemModule::filter($request)
            ->select('system_modules.id', 'system_modules.slug', 'system_modules.name', 'system_modules.is_active', 'system_modules.created_at', 'system_modules.updated_at', 'system_modules.created_by')
            ->with(['creator:id,name,email'])
            ->orderByFavoriteFirst($userId)
            ->paginate($perPage)
            ->withQueryString();

        // is_favorite viene como int 0/1 o bool según driver — normalizar.
        $system_modules->getCollection()->transform(function ($r) {
            $r->is_favorite = (bool) ($r->is_favorite ?? false);
            return $r;
        });

        $totalUnfiltered = SystemModule::count();

        // 'name' puede venir como string o array — normalizamos a array.
        $names = $request->get('name', []);
        if (is_string($names)) {
            $names = $names === '' ? [] : [$names];
        }

        return inertia('SystemModules/Index', [
            'system_modules' => array_merge($system_modules->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Límites de export por formato — el frontend deshabilita formatos
            // que exceden su límite. CSV con 0 = sin límite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('system_modules'),
            'filters' => [
                'name'         => array_values($names),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'updated_from' => $request->get('updated_from', ''),
                'updated_to'   => $request->get('updated_to', ''),
                'id_from'      => $request->get('id_from', ''),
                'id_to'        => $request->get('id_to', ''),
                'only_favorites' => $request->has('only_favorites')
                    ? filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN)
                    : false,
                'sort'         => $request->get('sort', 'id'),
                'direction'    => $request->get('direction', 'desc'),
                'per_page'     => $perPage,
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'filterSchema' => SystemModule::filterSchema(),
        ]);
    }

    /**
     * Normaliza `advanced_where` del request: viene como JSON string o
     * array directo. Filtra clausulas vacias o incompletas.
     */
    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: []; }
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, fn ($c) => is_array($c) && !empty($c['field']) && !empty($c['op'])));
    }

    public function create()
    {
        return inertia('SystemModules/Form', [
            'system_module' => null,
        ]);
    }

    public function store(StoreRequest $request, SystemModuleService $service)
    {
        $service->create($request->validated());

        return redirect()
            ->route('system_management.system_modules.index')
            ->with('success', __('global.created_success'));
    }

    /**
     * Solo super ve system_modulees soft-deleted (con motivo + deleter).
     * Otros usuarios reciben 404 — privacidad: no exponemos que existió.
     */
    public function show(Request $request, $slug)
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = SystemModule::with(['creator:id,name,email', 'deleter:id,name,email']);
        if ($isSuper) {
            $query->withTrashed();
        }

        $system_module = $query->where('slug', $slug)->firstOrFail();

        // Track recent view (best-effort, no rompe la pantalla si falla).
        if ($userId = $request->user()?->id) {
            try {
                \App\Models\UserRecentView::track($userId, SystemModule::class, $system_module->id);
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        // Activity feed para super/admin (mismo gate que /audit_logs).
        // AuditLogResource normaliza el shape — reutilizado por Show de todos
        // los módulos clonados.
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                \App\Models\AuditLog::query()
                    ->where('auditable_type', \App\Models\SystemModule::class)
                    ->where('auditable_id', $system_module->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        // Permissions reales en DB para este módulo — incluye las 6 canónicas
        // que crea el Observer y las custom que el super haya agregado.
        $permissions = \Spatie\Permission\Models\Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'like', $system_module->permission_key . '.%')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => [
                'id'     => $p->id,
                'name'   => $p->name,
                'action' => substr($p->name, strlen($system_module->permission_key) + 1),
            ]);

        return inertia('SystemModules/Show', [
            'system_module' => [
                'id'                  => $system_module->id,
                'slug'                => $system_module->slug,
                'name'                => $system_module->name,
                'permission_key'      => $system_module->permission_key,
                'is_active'           => $system_module->is_active,
                'created_at'          => $system_module->created_at,
                'updated_at'          => $system_module->updated_at,
                'deleted_at'          => $system_module->deleted_at,
                'deleted_description' => $system_module->deleted_description,
                'creator' => $system_module->creator ? [
                    'id'    => $system_module->creator->id,
                    'name'  => $system_module->creator->name,
                    'email' => $system_module->creator->email,
                ] : null,
                'deleter' => $system_module->deleter ? [
                    'id'    => $system_module->deleter->id,
                    'name'  => $system_module->deleter->name,
                    'email' => $system_module->deleter->email,
                ] : null,
            ],
            'permissions'        => $permissions,
            'canonicalActions'   => \App\Observers\SystemModuleObserver::CANONICAL_ACTIONS,
            'activity'           => $activity,
            'recordAudit'        => $this->recordAuditMeta($system_module),
        ]);
    }

    /**
     * Agrega una permission custom (acción no canónica) al módulo.
     * Las 6 canónicas (view, show, create, edit, delete, export) las maneja
     * el Observer automáticamente; este endpoint es para extender el set
     * (ej. agregar `import`, `approve`, `archive`).
     */
    public function storePermission(Request $request, SystemModule $system_module)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/'],
        ], [
            'action.regex' => __('system_modules.action_regex'),
        ]);

        $permissionName = "{$system_module->permission_key}.{$data['action']}";

        $exists = \Spatie\Permission\Models\Permission::where('name', $permissionName)
            ->where('guard_name', 'web')->exists();

        if ($exists) {
            return back()->with('error', __('system_modules.permission_exists', ['name' => $permissionName]));
        }

        \Spatie\Permission\Models\Permission::create([
            'name'       => $permissionName,
            'guard_name' => 'web',
        ]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('success', __('system_modules.permission_created', ['name' => $permissionName]));
    }

    /**
     * Elimina una permission. Atención: borra también las asignaciones a roles
     * y users (cascade vía pivots role_has_permissions / model_has_permissions).
     * El super debería pensarlo dos veces antes de borrar una de las 6
     * canónicas — el Observer NO la recrea automáticamente.
     */
    public function destroyPermission(Request $request, SystemModule $system_module, int $permissionId)
    {
        $permission = \Spatie\Permission\Models\Permission::where('id', $permissionId)
            ->where('guard_name', 'web')
            ->where('name', 'like', $system_module->permission_key . '.%')
            ->first();

        abort_unless($permission, 404);

        // Bloquear borrado de las canónicas — son inmutables incluso para super.
        $action = substr($permission->name, strlen($system_module->permission_key) + 1);
        if (in_array($action, \App\Observers\SystemModuleObserver::CANONICAL_ACTIONS, true)) {
            return back()->with('error', __('system_modules.permission_canonical_locked', ['name' => $permission->name]));
        }

        // Detach de roles/users — cascade manual porque las FK no tienen ON DELETE CASCADE.
        \DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
        \DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
        $permission->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return back()->with('success', __('system_modules.permission_deleted', ['name' => $permission->name]));
    }

    public function edit(SystemModule $system_module)
    {
        return inertia('SystemModules/Form', [
            'system_module' => [
                'id'              => $system_module->id,
                'slug'            => $system_module->slug,
                'name'            => $system_module->name,
                'permission_key'  => $system_module->permission_key,
                'is_active'       => $system_module->is_active,
            ],
        ]);
    }

    public function update(UpdateRequest $request, SystemModule $system_module, SystemModuleService $service)
    {
        $service->update($system_module, $request->validated());

        return redirect()
            ->route('system_management.system_modules.index')
            ->with('success', __('global.updated_success'));
    }

    // ── TRASH & RESTORE (super only) ──────────────────────────────────

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        // Reusa `scopeFilter` del modelo (mismo unaccent + case insensitive
        // que el listado principal). Sin esto duplicabamos la lógica raw SQL.
        // El sort default `deleted_at desc` se setea solo si no viene en el
        // request — coherente con index().
        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'deleted_at', 'direction' => 'desc']);
        }

        $system_modules = SystemModule::onlyTrashed()
            ->with(['deleter:id,name,email'])
            ->select('system_modules.id', 'system_modules.slug', 'system_modules.name', 'system_modules.is_active', 'system_modules.deleted_at', 'system_modules.deleted_by', 'system_modules.deleted_description', 'system_modules.created_at')
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        return inertia('SystemModules/Trash', [
            'system_modules' => $system_modules,
            'filters' => [
                'name'     => $request->get('name', ''),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, string $slug, SystemModuleService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $system_module = SystemModule::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($system_module);

        return redirect()
            ->route('system_management.system_modules.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(BulkRestoreRequest $request, SystemModuleService $service)
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.system_modules.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.system_modules.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    /**
     * Hard-delete con triple guard: super + onlyTrashed + nombre exacto.
     * Audit log se escribe ANTES del delete físico (sobrevive al borrado).
     */
    public function forceDelete(ForceDeleteRequest $request, string $slug, SystemModuleService $service)
    {
        $system_module = SystemModule::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data   = $request->validated();

        if (trim($data['name_confirmation']) !== $system_module->name) {
            return back()->withErrors([
                'name_confirmation' => __('global.force_delete_name_mismatch'),
            ]);
        }

        $service->forceDelete($system_module, $data['reason']);

        return redirect()
            ->route('system_management.system_modules.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ── SOFT-DELETE + UNDO + DUPLICATE ─────────────────────────────────────

    public function delete(SystemModule $system_module)
    {
        return inertia('SystemModules/Delete', [
            'system_module' => [
                'id'        => $system_module->id,
                'slug'      => $system_module->slug,
                'name'      => $system_module->name,
                'is_active' => $system_module->is_active,
            ],
            'dependents' => $system_module->countDependents(),
        ]);
    }

    public function deleteSave(DeleteRequest $request, SystemModule $system_module, SystemModuleService $service)
    {
        // Para SystemModule/countries block=false → no bloquea aquí. El patrón sirve
        // para módulos donde sí cuente (ej. doctors con appointments futuros).
        if ($system_module->hasBlockingDependents()) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $service->delete($system_module, $request->deleted_description);

        // Claim de undo en sesión (60s). La lógica de undo valida sesión,
        // no rol — quien eliminó puede deshacer aunque no sea super.
        $this->storeUndoableDelete([$system_module->id]);

        return redirect()
            ->route('system_management.system_modules.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$system_module->id]));
    }

    /**
     * Clona la región. El service maneja la generación del sufijo "(copia)"
     * con sanity guard. Si devuelve null → 100 intentos agotados (raro).
     */
    public function duplicate(Request $request, SystemModule $system_module, SystemModuleService $service)
    {
        $clone = $service->duplicate($system_module);

        if (!$clone) {
            return redirect()
                ->route('system_management.system_modules.index')
                ->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('system_management.system_modules.index')
            ->with('success', __('global.duplicated_success'));
    }

    // ── BULK OPERATIONS ─────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteRequest $request, SystemModuleService $service)
    {
        $data = $request->validated();

        // Service maneja threshold + dependency check + delete loop. No
        // ofrecemos undo en async: el delete real ocurre después del
        // redirect y el window de 60s no calza con un job que tarda minutos.
        $result = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.system_modules.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        if (!empty($result['blocked'])) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $this->storeUndoableDelete($result['deleted_ids']);

        return redirect()
            ->route('system_management.system_modules.index')
            ->with('success', __('global.deleted_success') . " ({$result['count']})")
            ->with('recentDelete', $this->buildRecentDeletePayload($result['deleted_ids']));
    }

    /**
     * Undo dentro del window de 60s. Validamos contra session claim (no rol):
     * quien eliminó puede deshacer su propio error sin ser super.
     * Defense in depth: además exigimos `deleted_by = current_user`.
     */
    public function undoLastDelete(Request $request, SystemModuleService $service)
    {
        $claim = session('system_modules.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('system_modules.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $userId = auth()->id();
        $system_modules = SystemModule::onlyTrashed()
            ->whereIn('id', $claim['ids'])
            ->where('deleted_by', $userId)
            ->get();

        if ($system_modules->isEmpty()) {
            session()->forget('system_modules.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        foreach ($system_modules as $system_module) {
            $service->restore($system_module);
        }
        session()->forget('system_modules.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    /** Persiste el claim en sesión por el window configurado para validar el undo. */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['system_modules.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds((int) config('system_modules.undo_window_seconds', 60)),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => (int) config('system_modules.undo_window_seconds', 60),
        ];
    }

    public function bulkSetActive(BulkSetActiveRequest $request, SystemModuleService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.system_modules.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.system_modules.index')
            ->with('success', __('global.updated_success') . " ({$result['changed']})");
    }

    // Edit All View (like index but with inline editing)
    // ── EDIT ALL (smart-table batch edit) ───────────────────────────────────

    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $system_modules = SystemModule::filter($request)
            ->select('system_modules.id', 'system_modules.slug', 'system_modules.name', 'system_modules.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('SystemModules/EditAll', [
            'system_modules' => $system_modules,
            'filters' => [
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

    /**
     * Batch update. Valida unicidad con UniqueNormalizedName (misma rule
     * que el Form) — duplicados intra-batch Y contra DB. Si hay errores,
     * no toca nada. Persistencia en transacción para atomicidad.
     */
    public function editAllUpdate(EditAllUpdateRequest $request, SystemModuleService $service)
    {
        $result = $service->editAllBatch($request->validated()['changes']);

        if (!empty($result['errors'])) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return redirect()
            ->route('system_management.system_modules.edit_all')
            ->with('success', __('global.updated_success') . " ({$result['touched']})");
    }

    // ── EXPORTS (queued jobs por formato) ───────────────────────────────────

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyección de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        $allowedColumns = ['name', 'is_active', 'created_at', 'updated_at', 'creator'];

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
            'title'                   => $data['title']                   ?? __('system_modules.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    public function exportPdf(Request $request, SystemModuleService $service)
    {
        return $this->dispatchExport($request, $service, 'pdf', \App\Jobs\SystemManagement\SystemModules\GenerateSystemModulesPdfJob::class);
    }

    public function exportExcel(Request $request, SystemModuleService $service)
    {
        return $this->dispatchExport($request, $service, 'excel', \App\Jobs\SystemManagement\SystemModules\GenerateSystemModulesExcelJob::class);
    }

    public function exportCsv(Request $request, SystemModuleService $service)
    {
        return $this->dispatchExport($request, $service, 'csv', \App\Jobs\SystemManagement\SystemModules\GenerateSystemModulesCsvJob::class);
    }

    public function exportWord(Request $request, SystemModuleService $service)
    {
        return $this->dispatchExport($request, $service, 'word', \App\Jobs\SystemManagement\SystemModules\GenerateSystemModulesWordJob::class);
    }

    /**
     * Helper común de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Reduce 5 líneas idénticas por endpoint a 1 método.
     */
    protected function dispatchExport(Request $request, SystemModuleService $service, string $format, string $jobClass): \Illuminate\Http\RedirectResponse
    {
        $options = $this->buildExportOptions($request, $format);
        $this->assertExportLimit($service, $format, $options);
        $service->recordExportAudit($format, $options);
        $jobClass::dispatch(auth()->id(), $options);
        return back()->with('success', __('global.download_in_queue'));
    }

    /**
     * Valida que el dataset no exceda el límite del formato. Usuarios con
     * plan premium (feature flag `export_unlimited_rows`) saltean el límite.
     */
    protected function assertExportLimit(SystemModuleService $service, string $format, array $options): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limit = \App\Models\Setting::getExportLimit('system_modules', $format);
        if ($limit === 0) return;  // CSV streaming, sin límite

        $count = $service->countForExport($options);
        if ($count > $limit) {
            abort(422, __('system_modules.export_limit_exceeded', [
                'count'  => number_format($count),
                'limit'  => number_format($limit),
                'format' => strtoupper($format),
            ]));
        }
    }

    // ── IMPORT (two-phase: dry_run preview + commit) ────────────────────────
    // El frontend sube 2 veces: primero con dry_run=true (preview con summary),
    // después con dry_run=false (commit). No usamos temp storage para mantenerlo
    // simple — el archivo viaja por la wire 2 veces, OK para uploads chicos.

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SystemManagement\SystemModules\SystemModulesImportTemplate(),
            __('system_modules.import_template_filename')
        );
    }

    public function import(ImportRequest $request, SystemModuleService $service)
    {
        $data   = $request->validated();
        $result = $service->processImport(
            $data['file'],
            $data['mode'] ?? 'update_or_create',
            filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}

