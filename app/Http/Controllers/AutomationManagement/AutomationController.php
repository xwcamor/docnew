<?php

namespace App\Http\Controllers\AutomationManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\AutomationManagement\Automation\BulkDeleteAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\BulkRestoreAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\BulkSetActiveAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\DeleteAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\EditAllUpdateAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\ForceDeleteAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\ImportAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\StoreAutomationRequest;
use App\Http\Requests\AutomationManagement\Automation\UpdateAutomationRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\Automations\RunAutomationJob;
use App\Jobs\AutomationManagement\Automations\GenerateAutomationsCsvJob;
use App\Jobs\AutomationManagement\Automations\GenerateAutomationsExcelJob;
use App\Jobs\AutomationManagement\Automations\GenerateAutomationsPdfJob;
use App\Jobs\AutomationManagement\Automations\GenerateAutomationsWordJob;
use App\Models\Automation;
use App\Models\AuditLog;
use App\Services\Automations\ActionRegistry;
use App\Services\Automations\AutomationRunner;
use App\Services\Automations\DataSourceRegistry;
use App\Services\AutomationManagement\AutomationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Automations — gestión de reglas de automatización per-tenant.
 *
 * Acceso protegido por dos middleware:
 *   - plan_feature:automations → solo planes con la feature activa
 *   - role o permission, según routes
 *
 * Las automations son tenant-scoped automáticamente (BelongsToTenant trait).
 * Super ve todas y puede gestionar cualquiera.
 *
 * Patrón clonado de Regions (master template): filtros + favoritos + bulk +
 * undo 60s + edit-all + duplicate + drawer details.
 */
class AutomationController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: []; }
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, fn ($c) => is_array($c) && !empty($c['field']) && !empty($c['op'])));
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $advanced = $this->parseAdvancedWhere($request);

        $userId = $request->user()?->id;
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $automations = Automation::query()
            ->select(
                'automations.id', 'automations.name', 'automations.description',
                'automations.tenant_id',
                'automations.is_active', 'automations.trigger_type', 'automations.trigger_config',
                'automations.data_source', 'automations.action_type', 'automations.action_config',
                'automations.last_run_at', 'automations.next_run_at',
                'automations.runs_count', 'automations.failures_count',
                'automations.created_at', 'automations.updated_at', 'automations.created_by',
            )
            // El super ve cross-tenant, asi que necesita identificar a que
            // workspace pertenece cada automation. Eager-load del tenant solo
            // para super — para admin todos pertenecen al mismo tenant.
            ->with(array_merge(
                ['creator:id,name,email'],
                $isSuper ? ['tenant:id,name'] : []
            ))
            ->when($request->filled('name'), function ($q) use ($request) {
                $names = is_array($request->name) ? $request->name : [$request->name];
                $names = array_filter(array_map('trim', $names), fn ($n) => $n !== '');
                if (count($names) === 0) return;
                $isPgsql = DB::getDriverName() === 'pgsql';
                $q->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(automations.name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('automations.name', 'like', '%' . $name . '%');
                        }
                    }
                });
            })
            ->when($request->filled('is_active'), fn ($q) => $q->where(
                'automations.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
            ))
            ->when($request->filled('data_source'), function ($q) use ($request) {
                $values = is_array($request->data_source) ? $request->data_source : [$request->data_source];
                $q->whereIn('automations.data_source', array_values(array_filter($values, fn ($v) => $v !== '')));
            })
            ->when($request->filled('action_type'), function ($q) use ($request) {
                $values = is_array($request->action_type) ? $request->action_type : [$request->action_type];
                $q->whereIn('automations.action_type', array_values(array_filter($values, fn ($v) => $v !== '')));
            })
            ->when($request->filled('created_from'), fn ($q) => $q->where('automations.created_at', '>=', $request->created_from . ' 00:00:00'))
            ->when($request->filled('created_to'),   fn ($q) => $q->where('automations.created_at', '<=', $request->created_to . ' 23:59:59'))
            ->when(!empty($advanced), fn ($q) => \App\Services\Automations\Support\FilterApplier::apply($q, ['where' => $advanced], \App\Models\Automation::filterSchema()))
            ->when($request->filled('only_favorites') && filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN), function ($q) use ($userId) {
                if (!$userId) return;
                $q->whereExists(function ($qq) use ($userId) {
                    $qq->select(DB::raw(1))
                        ->from('user_favorites')
                        ->whereColumn('user_favorites.favoritable_id', 'automations.id')
                        ->where('user_favorites.favoritable_type', Automation::class)
                        ->where('user_favorites.user_id', $userId);
                });
            })
            ->when(in_array($request->get('sort'), ['id', 'name', 'is_active', 'created_at', 'next_run_at', 'runs_count']), function ($q) use ($request) {
                $direction = in_array($request->get('direction'), ['asc', 'desc']) ? $request->get('direction') : 'desc';
                $q->orderBy('automations.' . $request->get('sort'), $direction);
            })
            // Orden por workspace (solo lo ve el super): nombre vía left join.
            ->when($request->get('sort') === 'tenant', function ($q) use ($request) {
                $direction = in_array($request->get('direction'), ['asc', 'desc']) ? $request->get('direction') : 'desc';
                $q->leftJoin('tenants', 'tenants.id', '=', 'automations.tenant_id')
                  ->orderBy('tenants.name', $direction);
            })
            ->orderByFavoriteFirst($userId)
            ->paginate($perPage)
            ->withQueryString();

        // Normalizar is_favorite a bool (Postgres true/false vs SQLite 0/1).
        $automations->getCollection()->transform(function ($a) {
            $a->is_favorite = (bool) ($a->is_favorite ?? false);
            return $a;
        });

        $totalUnfiltered = Automation::count();

        // 'name' puede venir como string o array — normalizamos a array.
        $names = $request->get('name', []);
        if (is_string($names)) {
            $names = $names === '' ? [] : [$names];
        }
        $dataSources = $request->get('data_source', []);
        if (is_string($dataSources)) {
            $dataSources = $dataSources === '' ? [] : [$dataSources];
        }
        $actionTypes = $request->get('action_type', []);
        if (is_string($actionTypes)) {
            $actionTypes = $actionTypes === '' ? [] : [$actionTypes];
        }

        return inertia('Automations/Index', [
            'automations' => array_merge($automations->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            'catalog'     => $this->buildCatalog(),
            'filterSchema' => \App\Models\Automation::filterSchema(),
            // Limites de export por formato — el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('automations'),
            'filters' => [
                'name'         => array_values($names),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'data_source'  => array_values($dataSources),
                'action_type'  => array_values($actionTypes),
                'created_from' => $request->get('created_from', ''),
                'created_to'   => $request->get('created_to', ''),
                'only_favorites' => $request->has('only_favorites')
                    ? filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN)
                    : false,
                'advanced_where' => $advanced,
                'sort'         => $request->get('sort', 'id'),
                'direction'    => $request->get('direction', 'desc'),
                'per_page'     => $perPage,
            ],
        ]);
    }

    public function create(DataSourceRegistry $sources, ActionRegistry $actions)
    {
        return inertia('Automations/Form', [
            'automation' => null,
            'catalog'    => $this->buildCatalog(),
        ]);
    }

    public function store(StoreAutomationRequest $request, AutomationService $service): RedirectResponse
    {
        $service->create($request->validated());

        return redirect()
            ->route('automation_management.automations.index')
            ->with('success', __('automations.created'));
    }

    public function show(Request $request, Automation $automation)
    {
        $automation->load(['creator:id,name,email', 'deleter:id,name,email']);

        $recentRuns = $automation->runs()->limit(20)->get();

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Automation::class)
                    ->where('auditable_id', $automation->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        // Track recent view (best-effort, no rompe la pantalla si falla).
        if ($userId = $request->user()?->id) {
            try {
                \App\Models\UserRecentView::track($userId, Automation::class, $automation->id);
            } catch (\Throwable $e) {
                // silent fail
            }
        }

        return inertia('Automations/Show', [
            'automation' => $this->payload($automation, withAudit: true),
            'runs'       => $recentRuns,
            'activity'   => $activity,
            'recordAudit' => $this->recordAuditMeta($automation),
            'catalog'    => $this->buildCatalog(),
        ]);
    }

    public function edit(Automation $automation)
    {
        return inertia('Automations/Form', [
            'automation' => $this->payload($automation),
            'catalog'    => $this->buildCatalog(),
        ]);
    }

    public function update(UpdateAutomationRequest $request, Automation $automation, AutomationService $service): RedirectResponse
    {
        $service->update($automation, $request->validated());

        return redirect()
            ->route('automation_management.automations.index')
            ->with('success', __('automations.saved'));
    }

    // ── SOFT-DELETE + UNDO + DUPLICATE ──────────────────────────────────────

    public function delete(Automation $automation)
    {
        return inertia('Automations/Delete', [
            'automation' => $this->payload($automation),
        ]);
    }

    public function deleteSave(DeleteAutomationRequest $request, Automation $automation, AutomationService $service): RedirectResponse
    {
        $service->delete(
            $automation,
            $request->validated()['deleted_description'],
            (int) $request->user()?->id,
        );

        // Claim de undo en sesión (60s). Quien eliminó puede deshacer.
        $this->storeUndoableDelete([$automation->id]);

        return redirect()
            ->route('automation_management.automations.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$automation->id]));
    }

    /**
     * Clona la automation. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, Automation $automation, AutomationService $service): RedirectResponse
    {
        $clone = $service->duplicate($automation);

        if (!$clone) {
            return redirect()
                ->route('automation_management.automations.index')
                ->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('automation_management.automations.index')
            ->with('success', __('global.duplicated_success'));
    }

    // ── TRASH + RESTORE + FORCE_DELETE (super) ────────────────────────

    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $automations = Automation::onlyTrashed()
            ->with('deleter:id,name,email')
            ->orderByDesc('deleted_at')
            ->paginate(25);

        return inertia('Automations/Trash', [
            'automations' => $automations,
        ]);
    }

    public function restore(Request $request, $automation, AutomationService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = Automation::onlyTrashed()->findOrFail($automation);
        $service->restore($model);

        return redirect()
            ->route('automation_management.automations.trash')
            ->with('success', __('global.restored_success'));
    }

    public function forceDelete(ForceDeleteAutomationRequest $request, $automation, AutomationService $service): RedirectResponse
    {
        $model = Automation::onlyTrashed()->findOrFail($automation);
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors([
                'name_confirmation' => __('global.force_delete_name_mismatch'),
            ]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('automation_management.automations.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ── ACCIONES DIRECTAS ────────────────────────────────────────────────

    /** Toggle is_active sin abrir el form. Conveniente desde la lista. */
    public function toggleActive(Request $request, Automation $automation, AutomationService $service): RedirectResponse
    {
        $service->toggleActive($automation);

        return back()->with('success', __('global.updated_success'));
    }

    /**
     * Dispara la automation AHORA (test run). Corre SÍNCRONO para dar feedback
     * inmediato (no depende de que haya un queue:work corriendo) e ignora
     * is_active a propósito: un test manual debe ejecutarse aunque la
     * automation esté pausada. El runner captura sus propios errores y deja el
     * AutomationRun en 'failed', así que devolvemos el resultado real.
     */
    public function runNow(Request $request, Automation $automation, AutomationRunner $runner): RedirectResponse
    {
        $run = $runner->run($automation);

        if ($run->status === 'success') {
            return back()->with('success', __('automations.run_done', ['summary' => $run->output_summary ?: '—']));
        }

        return back()->with('error', __('automations.run_failed', ['error' => $run->error_message ?: '—']));
    }

    // ── BULK OPERATIONS ─────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteAutomationRequest $request, AutomationService $service): RedirectResponse
    {
        $data   = $request->validated();
        $result = $service->bulkDelete(
            $data['ids'],
            $data['deleted_description'],
            (int) $request->user()?->id,
        );

        if (!empty($result['queued'])) {
            return redirect()
                ->route('automation_management.automations.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        $deletedIds = $result['deleted'];
        $this->storeUndoableDelete($deletedIds);

        $count = count($deletedIds);
        return redirect()
            ->route('automation_management.automations.index')
            ->with('success', __('global.deleted_success') . " ({$count})")
            ->with('recentDelete', $this->buildRecentDeletePayload($deletedIds));
    }

    public function bulkSetActive(BulkSetActiveAutomationRequest $request, AutomationService $service): RedirectResponse
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('automation_management.automations.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('automation_management.automations.index')
            ->with('success', __('global.updated_success') . " ({$result['changed']})");
    }

    public function bulkRestore(BulkRestoreAutomationRequest $request, AutomationService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('automation_management.automations.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('automation_management.automations.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    // ── UNDO LAST DELETE (60s window) ────────────────────────────────────

    /**
     * Undo dentro del window de 60s. Validamos contra session claim (no rol):
     * quien eliminó puede deshacer su propio error sin ser super.
     * Defense in depth: el service solo restaura filas con
     * `deleted_by = current_user`.
     */
    public function undoLastDelete(Request $request, AutomationService $service): RedirectResponse
    {
        $claim = session('automations.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('automations.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) $request->user()?->id);

        if (empty($restored)) {
            session()->forget('automations.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('automations.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    /** Persiste el claim en sesión por el window configurado para validar el undo. */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['automations.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds((int) config('automations.undo_window_seconds', 60)),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => (int) config('automations.undo_window_seconds', 60),
        ];
    }

    // ── EDIT ALL (batch edit name + is_active) ──────────────────────────────

    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $automations = Automation::query()
            ->select('automations.id', 'automations.name', 'automations.is_active')
            ->when($request->filled('name'), function ($q) use ($request) {
                $name = is_array($request->name) ? ($request->name[0] ?? '') : $request->name;
                $q->where('automations.name', 'like', '%' . $name . '%');
            })
            ->when($request->filled('is_active'), fn ($q) => $q->where(
                'automations.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN),
            ))
            ->when(in_array($request->get('sort'), ['id', 'name', 'is_active']), function ($q) use ($request) {
                $direction = in_array($request->get('direction'), ['asc', 'desc']) ? $request->get('direction') : 'asc';
                $q->orderBy('automations.' . $request->get('sort'), $direction);
            })
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Automations/EditAll', [
            'automations' => $automations,
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
     * Batch update. Persistencia en transacción para atomicidad. Si el
     * is_active cambia, recalculamos next_run_at acorde.
     */
    public function editAllUpdate(EditAllUpdateAutomationRequest $request, AutomationService $service): RedirectResponse
    {
        $touched = $service->editAllUpdate($request->validated()['changes']);

        return redirect()
            ->route('automation_management.automations.edit_all')
            ->with('success', __('global.updated_success') . " ({$touched})");
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    protected function payload(Automation $a, bool $withAudit = false): array
    {
        $base = [
            'id'                => $a->id,
            'name'              => $a->name,
            'description'       => $a->description,
            'is_active'         => $a->is_active,
            'trigger_type'      => $a->trigger_type,
            'trigger_config'    => $a->trigger_config ?? [],
            'data_source'       => $a->data_source,
            'data_filter'       => $a->data_filter ?? ['where' => [], 'limit' => 100],
            'action_type'       => $a->action_type,
            'action_config'     => $a->action_config ?? [],
            'last_run_at'       => $a->last_run_at,
            'next_run_at'       => $a->next_run_at,
            'runs_count'        => $a->runs_count,
            'failures_count'    => $a->failures_count,
            'created_at'        => $a->created_at,
            'updated_at'        => $a->updated_at,
            'deleted_at'        => $a->deleted_at,
        ];
        if ($withAudit) {
            $base['deleted_description'] = $a->deleted_description;
            $base['creator'] = $a->creator ? ['id' => $a->creator->id, 'name' => $a->creator->name, 'email' => $a->creator->email] : null;
            $base['deleter'] = $a->deleter ? ['id' => $a->deleter->id, 'name' => $a->deleter->name, 'email' => $a->deleter->email] : null;
        }
        return $base;
    }

    /**
     * Catálogo de data sources y actions para el frontend. Se pasa al form
     * para construir los selects y al show para mostrar labels.
     *
     * Incluye tambien:
     *   - template_variables: lista de placeholders soportados en body/title
     *     ({count}, {list}, {date}, {automation}) con su descripcion y ejemplo.
     *   - workspace_users: usuarios del workspace que el form ofrece como
     *     destinatarios (picker de emails) y para "specific_users" del in-app.
     */
    protected function buildCatalog(): array
    {
        return [
            'data_sources'       => app(DataSourceRegistry::class)->catalog(),
            'actions'            => app(ActionRegistry::class)->catalog(),
            'template_variables' => $this->templateVariables(),
            'workspace_users'    => $this->workspaceUsers(),
            // Remitente real de los emails — se muestra al user en el preview
            // para que sepa de qué cuenta saldrán los mensajes. Si no esta
            // configurado, devolvemos null y el frontend muestra un placeholder.
            'mail_from' => [
                'address' => config('mail.from.address') ?: null,
                'name'    => config('mail.from.name')    ?: null,
            ],
            // Lista de workspaces — solo para super (que crea automations
            // cross-tenant). Admin no la necesita: su tenant se autocompleta.
            'tenant_options' => $this->tenantOptions(),
        ];
    }

    /**
     * Workspaces disponibles para crear/editar una automation. Solo super
     * ve el selector — admin tiene su tenant fijo y se autoasigna via trait.
     * Para admin/user devolvemos lista vacia (el frontend no muestra el selector).
     */
    protected function tenantOptions(): array
    {
        $auth = auth()->user();
        if (!$auth || !$auth->hasRole('super')) return [];

        return \App\Models\Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->all();
    }

    /**
     * Lista de variables soportadas por EmailAction y InAppNotificationAction.
     * El frontend las muestra como chips clickeables que se insertan en el
     * textarea del body.
     */
    protected function templateVariables(): array
    {
        return [
            [
                'key'         => '{count}',
                'label'       => __('automations.var_count_label'),
                'description' => __('automations.var_count_desc'),
                'example'     => '42',
            ],
            [
                'key'         => '{list}',
                'label'       => __('automations.var_list_label'),
                'description' => __('automations.var_list_desc'),
                'example'     => "- Juan: 100\n- Maria: 200",
            ],
            [
                'key'         => '{date}',
                'label'       => __('automations.var_date_label'),
                'description' => __('automations.var_date_desc'),
                'example'     => now()->format('Y-m-d'),
            ],
            [
                'key'         => '{automation}',
                'label'       => __('automations.var_automation_label'),
                'description' => __('automations.var_automation_desc'),
                'example'     => __('automations.var_automation_example'),
            ],
        ];
    }

    /**
     * Usuarios del workspace para los pickers de destinatarios.
     * Super ve users de todos los tenants; admin solo los del suyo.
     * Excluye system users (api+slug@system.local) — son cuentas de tokens.
     *
     * Cada user trae flag `is_admin` para que el frontend pueda ofrecer
     * "Seleccionar todos los admins" como helper rapido.
     */
    protected function workspaceUsers(): array
    {
        $auth = auth()->user();
        $query = \App\Models\User::query()
            ->with('roles:id,name')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('email', 'not like', 'api+%@system.local')
                  ->orWhereNull('email');
            })
            ->orderBy('name');

        if ($auth && !$auth->hasRole('super')) {
            $query->where('tenant_id', $auth->tenant_id);
        }

        return $query
            ->limit(500)
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'is_admin' => $u->roles->contains(fn ($r) => in_array($r->name, ['super', 'admin'], true)),
            ])
            ->all();
    }

    // ── EXPORTS ─────────────────────────────────────────────────────────
    // Los 4 formatos van a queue como jobs async (mismo patron que Customers
    // y Regions). El job se encarga de la query con scope + render + Download.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateAutomationsCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateAutomationsExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateAutomationsPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateAutomationsWordJob::class);
    }

    /**
     * Helper comun de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Mismo patron que Customer / Region.
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

        $limit = \App\Models\Setting::getExportLimit('automations', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('automations.export_limit_exceeded', [
                'count'  => number_format($count),
                'limit'  => number_format($limit),
                'format' => strtoupper($format),
            ]));
        }
    }

    /** Cuenta filas a exportar segun scope+filters. Replica la logica de buildQuery() del job. */
    protected function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return Automation::query()->count();
        }

        // Filtros — replicamos los aplicados en el index para el conteo exacto.
        $f = $options['filters'] ?? [];
        $q = Automation::query();

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $names = array_filter(array_map('trim', $names), fn ($n) => $n !== '');
            if (!empty($names)) {
                $isPgsql = DB::getDriverName() === 'pgsql';
                $q->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(automations.name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('automations.name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $q->where('automations.is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($f['data_source'])) {
            $values = is_array($f['data_source']) ? $f['data_source'] : [$f['data_source']];
            $values = array_values(array_filter($values, fn ($v) => $v !== ''));
            if (!empty($values)) $q->whereIn('automations.data_source', $values);
        }
        if (!empty($f['action_type'])) {
            $values = is_array($f['action_type']) ? $f['action_type'] : [$f['action_type']];
            $values = array_values(array_filter($values, fn ($v) => $v !== ''));
            if (!empty($values)) $q->whereIn('automations.action_type', $values);
        }
        if (!empty($f['created_from'])) {
            $q->where('automations.created_at', '>=', $f['created_from'] . ' 00:00:00');
        }
        if (!empty($f['created_to'])) {
            $q->where('automations.created_at', '<=', $f['created_to'] . ' 23:59:59');
        }
        if (!empty($f['only_favorites']) && filter_var($f['only_favorites'], FILTER_VALIDATE_BOOLEAN)) {
            $userId = auth()->id();
            $q->whereExists(function ($qq) use ($userId) {
                $qq->select(DB::raw(1))
                    ->from('user_favorites')
                    ->whereColumn('user_favorites.favoritable_id', 'automations.id')
                    ->where('user_favorites.favoritable_type', Automation::class)
                    ->where('user_favorites.user_id', $userId);
            });
        }

        return $q->count();
    }

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyeccion de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        $allowedColumns = [
            'name', 'description', 'is_active', 'trigger', 'data_source', 'action_type',
            'runs_count', 'failures_count', 'last_run_at', 'next_run_at',
            'created_at', 'updated_at', 'creator',
        ];

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
            'title'                   => $data['title']                   ?? __('automations.export_title'),
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
            'auditable_type' => Automation::class,
            'auditable_id'   => null,
            'module'         => 'automations',
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
            'url'        => route('automation_management.automations.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AutomationManagement\Automations\AutomationsImportTemplate(),
            __('automations.import_template_filename')
        );
    }

    public function import(ImportAutomationRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $importer = new \App\Imports\AutomationManagement\Automations\AutomationsImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('AutomationsImport failed', [
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
}
