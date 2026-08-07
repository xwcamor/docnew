<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;

use App\Http\Requests\SystemManagement\Tenant\StoreRequest;
use App\Http\Requests\SystemManagement\Tenant\UpdateRequest;
use App\Http\Requests\SystemManagement\Tenant\DeleteRequest;
use App\Http\Requests\SystemManagement\Tenant\BulkDeleteRequest;
use App\Http\Requests\SystemManagement\Tenant\BulkSetActiveRequest;
use App\Http\Requests\SystemManagement\Tenant\BulkRestoreRequest;
use App\Http\Requests\SystemManagement\Tenant\ForceDeleteRequest;
use App\Http\Requests\SystemManagement\Tenant\EditAllUpdateRequest;
use App\Http\Requests\SystemManagement\Tenant\ImportRequest;

use App\Services\SystemManagement\TenantService;
use App\Services\SystemManagement\TenantSystemUserService;

use Illuminate\Http\Request;

/**
 * Tenants (Workspaces) controller — patrón Regions con particularidades:
 *   - plan (free|pro|enterprise) gating
 *   - logo upload (image, public disk)
 *   - system_user_id (User invisible que holds Sanctum tokens)
 *   - Show con tabs: info / users humanos / API tokens
 *
 * Permisos: viven dentro de role:super en routes/system_management.php.
 */
class TenantController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    // ─── INDEX ───────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $userId = $request->user()?->id;

        $tenants = Tenant::filter($request)
            ->select('tenants.id', 'tenants.slug', 'tenants.name', 'tenants.logo', 'tenants.is_active', 'tenants.system_user_id', 'tenants.created_at', 'tenants.updated_at', 'tenants.created_by')
            ->with(['creator:id,name,email', 'activeSubscription'])
            ->orderByFavoriteFirst($userId)
            ->paginate($perPage)
            ->withQueryString();

        $tenants->getCollection()->transform(function ($r) {
            $r->is_favorite = (bool) ($r->is_favorite ?? false);
            // El plan se deriva de la suscripción vigente (eager loaded
            // arriba — sin N+1). Se expone como atributo para el frontend.
            $r->plan = $r->currentPlan();
            return $r;
        });

        // Count human users per tenant (excluyendo system user).
        $humanUsersCount = User::withoutGlobalScopes()
            ->whereIn('tenant_id', collect($tenants->items())->pluck('id'))
            ->selectRaw('tenant_id, count(*) as total')
            ->whereNotIn('id', collect($tenants->items())->pluck('system_user_id')->filter())
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $tenantsArray = $tenants->toArray();
        foreach ($tenantsArray['data'] as &$row) {
            $row['users_count'] = (int) ($humanUsersCount[$row['id']] ?? 0);
        }
        unset($row);

        $totalUnfiltered = Tenant::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('Tenants/Index', [
            'tenants' => array_merge($tenantsArray, [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            'exportLimits' => \App\Models\Setting::getExportLimits('tenants'),
            'filters' => [
                'name'         => array_values($names),
                'plan'         => $request->get('plan', []),
                'type'         => $request->get('type', []),
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
            'planOptions' => $this->planOptions(),
            'filterSchema' => Tenant::filterSchema(),
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
        return inertia('Tenants/Form', [
            'tenant'      => null,
            'planOptions' => $this->planOptions(),
        ]);
    }

    public function store(StoreRequest $request, TenantService $service)
    {
        $service->create($request->validated(), $request->file('logo'));

        return redirect()
            ->route('system_management.tenants.index')
            ->with('success', __('global.created_success'));
    }

    /**
     * Opciones plan para selects del frontend. Lee de la tabla `plans` (DB),
     * NO de config — el super gestiona los tiers desde el módulo Plans
     * y los cambios deben reflejarse sin redeploy. Solo planes activos +
     * públicos aparecen como opción.
     */
    protected function planOptions(): array
    {
        return Plan::publicOptions();
    }

    // ─── SHOW (con tabs: info / users / tokens / audit) ──────────────────────
    public function show(Request $request, $slug)
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = Tenant::with([
            'creator:id,name,email',
            'deleter:id,name,email',
            'systemUser:id,email',
        ]);
        if ($isSuper) $query->withTrashed();

        $tenant = $query->where('slug', $slug)->firstOrFail();

        // Track recent view (best-effort).
        if ($userId = $request->user()?->id) {
            try {
                \App\Models\UserRecentView::track($userId, Tenant::class, $tenant->id);
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        // Users humanos (excluyendo system user). Incluye el rol asignado para
        // que el tab Users muestre quién es admin vs custom roles (Reception,
        // Soporte, etc.). Spatie eager-load: with('roles').
        $humanUsers = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', '!=', $tenant->system_user_id)
            ->with(['roles:id,name,description'])
            ->select('id', 'slug', 'name', 'email', 'is_active', 'created_at')
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id'         => $u->id,
                'slug'       => $u->slug,
                'name'       => $u->name,
                'email'      => $u->email,
                'is_active'  => $u->is_active,
                'created_at' => $u->created_at,
                // En este sistema un user tiene a lo sumo 1 rol activo.
                'role'       => $u->roles->first()?->name,
            ]);

        // Sanctum API tokens del system user.
        $tokens = collect();
        if ($tenant->systemUser) {
            $tokens = $tenant->systemUser->tokens()
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at', 'expires_at'])
                ->map(fn ($t) => [
                    'id'           => $t->id,
                    'name'         => $t->name,
                    'abilities'    => $t->abilities,
                    'last_used_at' => $t->last_used_at,
                    'created_at'   => $t->created_at,
                    'expires_at'   => $t->expires_at,
                ]);
        }

        // Subscriptions: sub activa (si la hay) + histórico completo.
        $activeSub = $tenant->activeSubscription;
        $subscriptionsHistory = $tenant->subscriptions()
            ->with('creator:id,name,email')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id'                  => $s->id,
                'plan'                => $s->plan,
                'status'              => $s->status,
                'starts_at'           => $s->starts_at,
                'ends_at'             => $s->ends_at,
                'trial_ends_at'       => $s->trial_ends_at,
                'cancelled_at'        => $s->cancelled_at,
                'cancellation_reason' => $s->cancellation_reason,
                'amount_paid'         => $s->amount_paid,
                'currency'            => $s->currency,
                'payment_method'      => $s->payment_method,
                'notes'               => $s->notes,
                'creator'             => $s->creator ? ['id' => $s->creator->id, 'name' => $s->creator->name] : null,
                'is_current'          => $s->isCurrent(),
                'days_remaining'      => $s->daysRemaining(),
            ]);

        // Activity feed (super / admin).
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                \App\Models\AuditLog::query()
                    ->where('auditable_type', Tenant::class)
                    ->where('auditable_id', $tenant->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Tenants/Show', [
            'tenant' => [
                'id'                  => $tenant->id,
                'slug'                => $tenant->slug,
                'name'                => $tenant->name,
                'logo'                => $tenant->logo,
                'logo_url'            => $tenant->logo_url,
                'plan'                => $tenant->currentPlan(),
                'is_active'           => $tenant->is_active,
                'created_at'          => $tenant->created_at,
                'updated_at'          => $tenant->updated_at,
                'deleted_at'          => $tenant->deleted_at,
                'deleted_description' => $tenant->deleted_description,
                'creator' => $tenant->creator ? [
                    'id'    => $tenant->creator->id,
                    'name'  => $tenant->creator->name,
                    'email' => $tenant->creator->email,
                ] : null,
                'deleter' => $tenant->deleter ? [
                    'id'    => $tenant->deleter->id,
                    'name'  => $tenant->deleter->name,
                    'email' => $tenant->deleter->email,
                ] : null,
                'system_user_email' => $tenant->systemUser?->email,
            ],
            'users'    => $humanUsers,
            'tokens'   => $tokens,
            'activity' => $activity,
            'currentPlan'          => $tenant->currentPlan(),
            // Indica si el plan vigente desbloquea api_access — el frontend lo
            // usa para mostrar el badge "dormido" sobre los tokens existentes
            // cuando el tenant bajó de plan (los tokens siguen en BD pero el
            // middleware plan_feature:api_access los bloquea hasta re-upgrade).
            'hasApiAccess'         => $tenant->canUseFeature('api_access'),
            'activeSubscription'   => $activeSub ? [
                'id'             => $activeSub->id,
                'plan'           => $activeSub->plan,
                'status'         => $activeSub->status,
                'starts_at'      => $activeSub->starts_at,
                'ends_at'        => $activeSub->ends_at,
                'trial_ends_at'  => $activeSub->trial_ends_at,
                'days_remaining' => $activeSub->daysRemaining(),
                'is_trial'       => $activeSub->isTrial(),
                'amount_paid'    => $activeSub->amount_paid,
                'currency'       => $activeSub->currency,
                'payment_method' => $activeSub->payment_method,
            ] : null,
            'subscriptionsHistory' => $subscriptionsHistory,
            'availablePlans'       => Plan::activeSlugs(),
            'plansComparison'      => Plan::publicComparisonData(),
            'recordAudit'          => $this->recordAuditMeta($tenant),
        ]);
    }

    public function edit(Tenant $tenant)
    {
        return inertia('Tenants/Form', [
            'tenant' => [
                'id'        => $tenant->id,
                'slug'      => $tenant->slug,
                'name'      => $tenant->name,
                'logo'      => $tenant->logo,
                'logo_url'  => $tenant->logo_url,
                'address'           => $tenant->address,
                'report_disclaimer' => $tenant->report_disclaimer,
                // Solo display — el plan NO se edita desde el form del tenant.
                // Los cambios de plan van por el tab Suscripción.
                'plan'      => $tenant->currentPlan(),
                'is_active' => $tenant->is_active,
                'timezone'  => $tenant->timezone,
            ],
            'planOptions' => $this->planOptions(),
        ]);
    }

    public function update(UpdateRequest $request, Tenant $tenant, TenantService $service)
    {
        $service->update($tenant, $request->validated(), $request->file('logo'));

        return redirect()
            ->route('system_management.tenants.index')
            ->with('success', __('global.updated_success'));
    }

    public function delete(Tenant $tenant)
    {
        return inertia('Tenants/Delete', [
            'tenant' => [
                'id'        => $tenant->id,
                'slug'      => $tenant->slug,
                'name'      => $tenant->name,
                'is_active' => $tenant->is_active,
            ],
        ]);
    }

    public function deleteSave(DeleteRequest $request, Tenant $tenant, TenantService $service)
    {
        $service->delete($tenant, $request->deleted_description);

        $this->storeUndoableDelete([$tenant->id]);

        return redirect()
            ->route('system_management.tenants.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$tenant->id]));
    }

    public function duplicate(Request $request, Tenant $tenant, TenantService $service)
    {
        $clone = $service->duplicate($tenant);

        if (!$clone) {
            return redirect()
                ->route('system_management.tenants.index')
                ->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('system_management.tenants.index')
            ->with('success', __('global.duplicated_success'));
    }

    // ─── TRASH + RESTORE ─────────────────────────────────────────────────────
    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'deleted_at', 'direction' => 'desc']);
        }

        $tenants = Tenant::onlyTrashed()
            ->with(['deleter:id,name,email', 'activeSubscription'])
            ->select('tenants.id', 'tenants.slug', 'tenants.name', 'tenants.is_active', 'tenants.deleted_at', 'tenants.deleted_by', 'tenants.deleted_description', 'tenants.created_at')
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        // Plan derivado de la suscripción vigente.
        $tenants->getCollection()->transform(function ($r) {
            $r->plan = $r->currentPlan();
            return $r;
        });

        return inertia('Tenants/Trash', [
            'tenants' => $tenants,
            'filters' => [
                'name'     => $request->get('name', ''),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, string $slug, TenantService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $tenant = Tenant::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($tenant);

        return redirect()
            ->route('system_management.tenants.trash')
            ->with('success', __('global.restored_success'));
    }

    public function forceDelete(ForceDeleteRequest $request, string $slug, TenantService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $tenant = Tenant::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->forceDelete($tenant, $request->validated()['reason']);

        return redirect()
            ->route('system_management.tenants.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ─── BULK OPERATIONS ─────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteRequest $request, TenantService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.tenants.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $this->storeUndoableDelete($result['deleted_ids']);

        return redirect()
            ->route('system_management.tenants.index')
            ->with('success', __('global.deleted_success') . " ({$result['count']})")
            ->with('recentDelete', $this->buildRecentDeletePayload($result['deleted_ids']));
    }

    public function bulkSetActive(BulkSetActiveRequest $request, TenantService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.tenants.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.tenants.index')
            ->with('success', __('global.updated_success') . " ({$result['changed']})");
    }

    public function bulkRestore(BulkRestoreRequest $request, TenantService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $data   = $request->validated();
        $result = $service->bulkRestore($data['ids']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.tenants.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.tenants.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function undoLastDelete(Request $request, TenantService $service)
    {
        $claim = session('tenants.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('tenants.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $userId = auth()->id();
        $tenants = Tenant::onlyTrashed()
            ->whereIn('id', $claim['ids'])
            ->where('deleted_by', $userId)
            ->get();

        if ($tenants->isEmpty()) {
            session()->forget('tenants.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        foreach ($tenants as $tenant) {
            $service->restore($tenant);
        }
        session()->forget('tenants.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['tenants.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds((int) config('tenants.undo_window_seconds', 60)),
        ]]);
    }

    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => (int) config('tenants.undo_window_seconds', 60),
        ];
    }

    // ─── EDIT ALL ────────────────────────────────────────────────────────────

    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        // El plan no es columna ni editable desde edit-all — edit-all solo
        // toca name + is_active.
        $tenants = Tenant::filter($request)
            ->select('tenants.id', 'tenants.slug', 'tenants.name', 'tenants.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Tenants/EditAll', [
            'tenants' => $tenants,
            'filters' => [
                'name'      => $request->get('name', ''),
                'is_active' => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'sort'      => $request->get('sort', 'id'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
            'planOptions' => $this->planOptions(),
        ]);
    }

    public function editAllUpdate(EditAllUpdateRequest $request, TenantService $service)
    {
        $result = $service->editAllBatch($request->validated()['changes']);

        if (!empty($result['errors'])) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return redirect()
            ->route('system_management.tenants.edit_all')
            ->with('success', __('global.updated_success') . " ({$result['touched']})");
    }

    // ─── EXPORTS ─────────────────────────────────────────────────────────────

    protected function buildExportOptions(Request $request, string $format): array
    {
        $allowedColumns = ['name', 'plan', 'is_active', 'created_at', 'updated_at', 'creator'];

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
            'title'                   => $data['title']                   ?? __('tenants.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    public function exportPdf(Request $request, TenantService $service)
    {
        return $this->dispatchExport($request, $service, 'pdf', \App\Jobs\SystemManagement\Tenants\GenerateTenantsPdfJob::class);
    }

    public function exportExcel(Request $request, TenantService $service)
    {
        return $this->dispatchExport($request, $service, 'excel', \App\Jobs\SystemManagement\Tenants\GenerateTenantsExcelJob::class);
    }

    public function exportCsv(Request $request, TenantService $service)
    {
        return $this->dispatchExport($request, $service, 'csv', \App\Jobs\SystemManagement\Tenants\GenerateTenantsCsvJob::class);
    }

    public function exportWord(Request $request, TenantService $service)
    {
        return $this->dispatchExport($request, $service, 'word', \App\Jobs\SystemManagement\Tenants\GenerateTenantsWordJob::class);
    }

    protected function dispatchExport(Request $request, TenantService $service, string $format, string $jobClass): \Illuminate\Http\RedirectResponse
    {
        $options = $this->buildExportOptions($request, $format);
        $this->assertExportLimit($service, $format, $options);
        $service->recordExportAudit($format, $options);
        $jobClass::dispatch(auth()->id(), $options);
        return back()->with('success', __('global.download_in_queue'));
    }

    protected function assertExportLimit(TenantService $service, string $format, array $options): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limit = \App\Models\Setting::getExportLimit('tenants', $format);
        if ($limit === 0) return;

        $count = $service->countForExport($options);
        if ($count > $limit) {
            abort(422, __('tenants.export_limit_exceeded', [
                'count'  => number_format($count),
                'limit'  => number_format($limit),
                'format' => strtoupper($format),
            ]));
        }
    }

    // ─── IMPORT ──────────────────────────────────────────────────────────────

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\SystemManagement\Tenants\TenantsImportTemplate(),
            __('tenants.import_template_filename')
        );
    }

    public function import(ImportRequest $request, TenantService $service)
    {
        $data   = $request->validated();
        $result = $service->processImport(
            $data['file'],
            $data['mode'] ?? 'update_or_create',
            filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    // ─── API TOKEN MANAGEMENT (preservado del controller original) ───────────

    /**
     * Crea un Sanctum API token tied al system user del workspace.
     * Devuelve el plain-text token UNA VEZ vía session flash.
     */
    public function createToken(Request $request, Tenant $tenant, TenantSystemUserService $systemUsers)
    {
        // Abilities REQUERIDAS: sin esto el token quedaba con ['*'] (acceso
        // total) y bypaseaba los middleware `abilities:...` de los endpoints
        // API. El cliente debe elegir explícitamente qué puede hacer el token.
        $data = $request->validate([
            'name'            => 'required|string|max:120',
            'abilities'       => 'required|array|min:1',
            'abilities.*'     => 'string|max:60',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $systemUser = $systemUsers->ensureFor($tenant);

        $expiresAt = !empty($data['expires_in_days'])
            ? now()->addDays($data['expires_in_days'])
            : null;

        $token = $systemUser->createToken(
            $data['name'],
            $data['abilities'],
            $expiresAt,
        );

        return back()
            ->with('success', __('tenants.token_created'))
            ->with('newToken', $token->plainTextToken);
    }

    public function revokeToken(Request $request, Tenant $tenant, int $tokenId)
    {
        if (! $tenant->systemUser) {
            return back()->with('error', __('tenants.no_system_user'));
        }

        $token = $tenant->systemUser->tokens()->where('id', $tokenId)->first();
        if (! $token) {
            return back()->with('error', __('tenants.token_not_found'));
        }

        $token->delete();

        return back()->with('success', __('tenants.token_revoked'));
    }
}
