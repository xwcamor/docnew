<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\ApproverRole\BulkDeleteApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\BulkRestoreApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\BulkSetActiveApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\DeleteApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\EditAllUpdateApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\ForceDeleteApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\ImportApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\StoreApproverRoleRequest;
use App\Http\Requests\BusinessManagement\ApproverRole\UpdateApproverRoleRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\ApproverRoles\GenerateApproverRolesCsvJob;
use App\Jobs\BusinessManagement\ApproverRoles\GenerateApproverRolesExcelJob;
use App\Jobs\BusinessManagement\ApproverRoles\GenerateApproverRolesPdfJob;
use App\Jobs\BusinessManagement\ApproverRoles\GenerateApproverRolesWordJob;
use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\AuditLog;
use App\Services\BusinessManagement\ApproverRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El catalogo de quien puede firmar un plan.
 *
 * Es pequeño a proposito: codigo, los dos nombres, el orden y si esta activo.
 * Lo que no es pequeño es lo que cuelga de el — cada regla de flujo nombra un
 * rol por su codigo — y por eso el borrado pasa siempre por el servicio.
 */
class ApproverRoleController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    public function index(Request $request, ApproverRoleService $service)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200], true) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'sort_order', 'direction' => 'asc']);
        }

        $isSuper = $request->user()?->hasRole('super') ?? false;

        // Sin eager-load de creator/deleter: el modelo es de dominio y no
        // declara esas relaciones. Los nombres se resuelven donde se necesitan.
        $query = ApproverRole::query()->select('approver_roles.*')
            ->when($isSuper, fn ($q) => $q->with('tenant:id,name'));
        $roles = ApproverRoleService::filter($query, $request)
            ->paginate($perPage)
            ->withQueryString();

        // Cuantas reglas usan cada rol: es el dato que decide si se puede
        // borrar, asi que se ve en la tabla y no solo al intentar borrarlo.
        $usos = ApprovalRule::query()
            ->whereIn('approver_role', collect($roles->items())->pluck('code'))
            ->selectRaw('approver_role, count(*) as total')
            ->groupBy('approver_role')
            ->pluck('total', 'approver_role');

        $roles->getCollection()->transform(function ($rol) use ($usos, $service) {
            $rol->rules_count = (int) ($usos[$rol->code] ?? 0);
            $rol->is_system   = $service->esDelSistema($rol);

            return $rol;
        });

        $terminos = $request->get('name', []);
        if (is_string($terminos)) {
            $terminos = $terminos === '' ? [] : [$terminos];
        }

        return inertia('ApproverRoles/Index', [
            'approver_roles' => array_merge($roles->toArray(), [
                'total_unfiltered' => ApproverRole::count(),
            ]),
            'exportLimits' => \App\Models\Setting::getExportLimits('approver_roles'),
            'filters' => [
                'name'         => array_values($terminos),
                'code'         => $request->get('code', ''),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'sort'         => $request->get('sort', 'sort_order'),
                'direction'    => $request->get('direction', 'asc'),
                'per_page'     => $perPage,
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'      => $isSuper,
            'filterSchema' => ApproverRoleService::filterSchema(),
        ]);
    }

    /** Normaliza `advanced_where`: llega como JSON o como array segun Inertia. */
    protected function parseAdvancedWhere(Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, fn ($c) => is_array($c) && ! empty($c['field']) && ! empty($c['op'])));
    }

    public function show(Request $request, ApproverRole $approverRole, ApproverRoleService $service)
    {
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', ApproverRole::class)
                    ->where('auditable_id', $approverRole->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        // Las reglas que firman con este rol: es la respuesta a "¿por que no
        // me deja borrarlo?" puesta antes de que la pregunta se haga.
        $reglas = ApprovalRule::with(['country:id,name', 'workType:id,code'])
            ->where('approver_role', $approverRole->code)
            ->orderBy('priority_level')
            ->get()
            ->map(fn ($r) => [
                'slug'           => $r->slug,
                'country'        => $r->country?->name,
                'work_type'      => $r->workType?->code,
                'priority_level' => $r->priority_level,
                'is_required'    => (bool) $r->is_required,
                'is_active'      => (bool) $r->is_active,
            ]);

        return inertia('ApproverRoles/Show', [
            'approverRole' => $this->payload($approverRole, withAudit: true, service: $service),
            'rules'        => $reglas,
            'recordAudit'  => $this->recordAuditMeta($approverRole),
            'activity'     => $activity,
        ]);
    }

    public function create()
    {
        return inertia('ApproverRoles/Form', [
            'approverRole' => null,
            'nextSortOrder' => (int) (ApproverRole::max('sort_order') ?? 0) + 1,
        ]);
    }

    public function store(StoreApproverRoleRequest $request, ApproverRoleService $service): RedirectResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && ApproverRole::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.approver_roles.index')
            ->with('success', __('approver_roles.created'));
    }

    public function edit(ApproverRole $approverRole, ApproverRoleService $service)
    {
        return inertia('ApproverRoles/Form', [
            'approverRole' => $this->payload($approverRole, service: $service),
        ]);
    }

    public function update(UpdateApproverRoleRequest $request, ApproverRole $approverRole, ApproverRoleService $service): RedirectResponse
    {
        $service->update($approverRole, $request->validated());

        return redirect()
            ->route('business_management.approver_roles.index')
            ->with('success', __('approver_roles.saved'));
    }

    /**
     * Confirmacion de borrado. Si el rol no se puede borrar, la pantalla lo
     * dice ANTES de pedir el motivo y ofrece desactivarlo en su lugar.
     */
    public function delete(ApproverRole $approverRole, ApproverRoleService $service)
    {
        return inertia('ApproverRoles/Delete', [
            'approverRole' => $this->payload($approverRole, service: $service),
            'blockedReason' => $service->motivoParaNoBorrar($approverRole),
        ]);
    }

    public function deleteSave(DeleteApproverRoleRequest $request, ApproverRole $approverRole, ApproverRoleService $service): RedirectResponse
    {
        try {
            $service->delete($approverRole, $request->validated()['deleted_description']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->storeUndoableDelete([$approverRole->id]);

        return redirect()
            ->route('business_management.approver_roles.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', ['count' => 1, 'seconds' => 60]);
    }

    /** Desactivar: la alternativa al borrado cuando el rol ya esta en uso. */
    public function deactivate(ApproverRole $approverRole, ApproverRoleService $service): RedirectResponse
    {
        $service->deactivate($approverRole);

        return redirect()
            ->route('business_management.approver_roles.index')
            ->with('success', __('approver_roles.deactivated'));
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['approver_roles.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds(60)->toIso8601String(),
        ]]);
    }

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $termino = $request->get('name', '');
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        $roles = ApproverRole::onlyTrashed()
            ->when($termino !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name_es', 'like', "%{$termino}%")
                ->orWhere('code', 'like', "%{$termino}%")))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        // Quién borró cada fila, en una consulta y no en N: el modelo no lleva
        // la relación y la papelera necesita el nombre, no el id.
        $nombres = \App\Models\User::withTrashed()
            ->whereIn('id', $roles->pluck('deleted_by')->filter()->unique())
            ->pluck('name', 'id');
        $roles->getCollection()->transform(function ($rol) use ($nombres) {
            $rol->deleter_name = $nombres[$rol->deleted_by] ?? null;

            return $rol;
        });

        return inertia('ApproverRoles/Trash', [
            'approver_roles' => $roles,
            'filters' => ['name' => $termino, 'per_page' => $perPage],
        ]);
    }

    public function restore(Request $request, $slug, ApproverRoleService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $rol = ApproverRole::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($rol);

        return redirect()
            ->route('business_management.approver_roles.trash')
            ->with('success', __('global.restored_success'));
    }

    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'sort_order', 'direction' => 'asc']);
        }

        $query = ApproverRole::query()
            ->select('approver_roles.id', 'approver_roles.slug', 'approver_roles.code',
                     'approver_roles.name_es', 'approver_roles.name_en',
                     'approver_roles.sort_order', 'approver_roles.is_active');

        return inertia('ApproverRoles/EditAll', [
            'approver_roles' => ApproverRoleService::filter($query, $request)->paginate($perPage)->withQueryString(),
            'filters' => [
                'name'      => $request->get('name', ''),
                'is_active' => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'sort'      => $request->get('sort', 'sort_order'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
        ]);
    }

    public function editAllUpdate(EditAllUpdateApproverRoleRequest $request, ApproverRoleService $service): RedirectResponse
    {
        $tocados = $service->editAllUpdate($request->validated()['changes']);

        return redirect()
            ->route('business_management.approver_roles.edit_all')
            ->with('success', __('global.updated_success') . " ({$tocados})");
    }

    public function bulkRestore(BulkRestoreApproverRoleRequest $request, ApproverRoleService $service): RedirectResponse
    {
        $resultado = $service->bulkRestore($request->validated()['ids']);

        if (! empty($resultado['queued'])) {
            return redirect()
                ->route('business_management.approver_roles.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        return redirect()
            ->route('business_management.approver_roles.trash')
            ->with('success', __('global.restored_success') . " ({$resultado['restored']})");
    }

    public function forceDelete(ForceDeleteApproverRoleRequest $request, $slug, ApproverRoleService $service): RedirectResponse
    {
        $rol  = ApproverRole::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data = $request->validated();

        // Se confirma escribiendo el codigo: es la clave del rol, y es lo que
        // se ve en el listado de la papelera.
        if (trim($data['name_confirmation']) !== $rol->code) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($rol, $data['reason']);

        return redirect()
            ->route('business_management.approver_roles.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(ApproverRole $m, bool $withAudit = false, ?ApproverRoleService $service = null): array
    {
        $service ??= app(ApproverRoleService::class);

        $base = [
            'id'          => $m->id,
            'slug'        => $m->slug,
            'code'        => $m->code,
            'name_es'     => $m->name_es,
            'name_en'     => $m->name_en,
            'sort_order'  => $m->sort_order,
            'is_active'   => $m->is_active,
            'tenant_id'   => $m->tenant_id,
            'is_system'   => $service->esDelSistema($m),
            'rules_count' => $service->reglasQueLoUsan($m),
            'created_at'  => $m->created_at,
            'updated_at'  => $m->updated_at,
            'deleted_at'  => $m->deleted_at,
        ];

        if ($withAudit) {
            $base['deleted_description'] = $m->deleted_description;
            $base['deleter'] = $m->deleted_by
                ? ['name' => \App\Models\User::withTrashed()->find($m->deleted_by)?->name]
                : null;
        }

        return $base;
    }

    // ── Exportar ─────────────────────────────────────────────────────────────

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateApproverRolesCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateApproverRolesExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateApproverRolesPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateApproverRolesWordJob::class);
    }

    protected function dispatchExport(Request $request, string $formato, string $jobClass): RedirectResponse
    {
        $opciones = $this->buildExportOptions($request, $formato);
        $this->assertExportLimit($formato, $opciones);
        $this->recordExportAudit($formato, $opciones);
        $jobClass::dispatch(auth()->id(), $opciones);

        return back()->with('success', __('global.download_in_queue'));
    }

    protected function assertExportLimit(string $formato, array $opciones): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limite = \App\Models\Setting::getExportLimit('approver_roles', $formato);
        if ($limite === 0) {
            return; // CSV en streaming: sin limite
        }

        $total = $this->countForExport($opciones);
        if ($total > $limite) {
            abort(422, __('approver_roles.export_limit_exceeded', [
                'count'  => number_format($total),
                'limit'  => number_format($limite),
                'format' => strtoupper($formato),
            ]));
        }
    }

    protected function countForExport(array $opciones): int
    {
        $scope = $opciones['scope'] ?? 'filtered';

        if ($scope === 'selected') {
            return count($opciones['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return ApproverRole::query()->count();
        }

        return ApproverRoleService::filter(
            ApproverRole::query(),
            new Request($opciones['filters'] ?? []),
        )->count();
    }

    // ── Importar (dos fases: vista previa + confirmacion) ────────────────────

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\ApproverRoles\ApproverRolesImportTemplate(),
            __('approver_roles.import_template_filename')
        );
    }

    public function import(ImportApproverRoleRequest $request)
    {
        $data   = $request->validated();
        $modo   = $data['mode'] ?? 'update_or_create';
        $dryRun = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Un super sin workspace importaria contra el catalogo global, que es
        // justo el que las reglas sembradas usan. No se le deja.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('approver_roles.import_super_blocked'),
            ], 422);
        }

        $importador = new \App\Imports\BusinessManagement\ApproverRoles\ApproverRolesImport(
            mode:   $modo,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importador, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('ApproverRolesImport failed', ['error' => $e->getMessage()]);

            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => $this->humanizeImportError($e),
            ], 422);
        }

        return response()->json(['ok' => true, 'dry_run' => $dryRun, 'summary' => $importador->summary()], 200);
    }

    /** El detalle tecnico va al log; al usuario le llega el motivo en castellano. */
    protected function humanizeImportError(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            $msg = $e->getMessage();
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

    // ── Masivas ──────────────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteApproverRoleRequest $request, ApproverRoleService $service): RedirectResponse
    {
        $data = $request->validated();
        $resultado = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if (! empty($resultado['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        if ($resultado['count'] === 0) {
            return back()->with('error', __('approver_roles.bulk_all_protected', ['count' => $resultado['skipped']]));
        }

        $this->storeUndoableDelete($resultado['deleted']);

        $msg = __('global.deleted_success') . ' (' . $resultado['count'] . ')';
        if ($resultado['skipped'] > 0) {
            $msg .= ' · ' . __('approver_roles.bulk_skipped_protected', ['count' => $resultado['skipped']]);
        }

        return back()
            ->with('success', $msg)
            ->with('recentDelete', ['count' => $resultado['count'], 'seconds' => 60]);
    }

    public function undoLastDelete(Request $request, ApproverRoleService $service): RedirectResponse
    {
        $claim = session('approver_roles.recent_delete');
        if (! $claim || ! is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('approver_roles.recent_delete');

            return back()->with('error', __('global.undo_failed'));
        }

        $restaurados = $service->undoLastDelete($claim['ids'], (int) auth()->id());
        session()->forget('approver_roles.recent_delete');

        return empty($restaurados)
            ? back()->with('error', __('global.undo_failed'))
            : back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveApproverRoleRequest $request, ApproverRoleService $service): RedirectResponse
    {
        $data = $request->validated();
        $resultado = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if (! empty($resultado['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        return back()->with('success', __('global.updated_success') . " ({$resultado['changed']})");
    }

    // ── Apoyo de exportacion ─────────────────────────────────────────────────

    protected function buildExportOptions(Request $request, string $formato): array
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $columnasPermitidas = array_values(array_filter([
            'code', 'name_es', 'name_en', 'sort_order', 'is_active',
            $isSuper ? 'tenant' : null,
            'created_at', 'updated_at',
        ]));

        if ($isSuper) {
            $columnasPermitidas = array_merge(['id', 'slug'], $columnasPermitidas);
        }

        $reglas = [
            'scope'                   => 'nullable|in:filtered,selected,all',
            'selected_ids'            => 'array',
            'selected_ids.*'          => 'integer',
            'columns'                 => 'array|min:1',
            'columns.*'               => 'in:' . implode(',', $columnasPermitidas),
            'title'                   => 'nullable|string|max:120',
            'include_filters_summary' => 'boolean',
            'filters'                 => 'array',
        ];
        if ($formato === 'pdf') {
            $reglas['orientation'] = 'nullable|in:portrait,landscape';
            $reglas['paper_size']  = 'nullable|in:a4,letter';
        }
        if ($formato === 'excel') {
            $reglas['autofilter']    = 'boolean';
            $reglas['freeze_header'] = 'boolean';
        }

        $data = $request->validate($reglas);

        return [
            'scope'                   => $data['scope']                   ?? 'filtered',
            'selected_ids'            => $data['selected_ids']            ?? [],
            'columns'                 => $data['columns']                 ?? $columnasPermitidas,
            'title'                   => $data['title']                   ?? __('approver_roles.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    protected function recordExportAudit(string $formato, array $opciones): void
    {
        AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => ApproverRole::class,
            'auditable_id'   => null,
            'module'         => 'approver_roles',
            'old_values'     => null,
            'new_values'     => [
                'format'  => $formato,
                'scope'   => $opciones['scope']   ?? 'filtered',
                'columns' => $opciones['columns'] ?? [],
                'filters' => $opciones['filters'] ?? [],
            ],
            'url'        => route('business_management.approver_roles.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
