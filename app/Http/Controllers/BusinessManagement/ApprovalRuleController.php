<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\ApprovalRule\BulkDeleteApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\BulkRestoreApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\BulkSetActiveApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\DeleteApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\EditAllUpdateApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\ForceDeleteApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\ImportApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\StoreApprovalRuleRequest;
use App\Http\Requests\BusinessManagement\ApprovalRule\UpdateApprovalRuleRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\ApprovalRules\GenerateApprovalRulesCsvJob;
use App\Jobs\BusinessManagement\ApprovalRules\GenerateApprovalRulesExcelJob;
use App\Jobs\BusinessManagement\ApprovalRules\GenerateApprovalRulesPdfJob;
use App\Jobs\BusinessManagement\ApprovalRules\GenerateApprovalRulesWordJob;
use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\AuditLog;
use App\Models\Country;
use App\Services\BusinessManagement\ApprovalRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Las reglas del flujo de aprobación de un plan.
 *
 * Lo que esta pantalla tiene que dejar claro, porque es donde la gente se
 * equivoca: una regla sin tipo de trabajo vale para todos los tipos, y en
 * cuanto un tipo tiene reglas propias las generales dejan de contar para él.
 * De ahí la vista previa (`preview`): sin ella nadie puede saber qué acaba de
 * configurar.
 */
class ApprovalRuleController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /**
     * Pone el candado. Una regla de aprobacion dice quien tiene que firmar un
     * plan; cambiarla a mitad de una obra cambia lo que se le exige a lo que ya
     * esta en marcha. Con el candado puesto hay que quitarlo a proposito.
     */
    public function lock(Request $request, ApprovalRule $approvalRule): RedirectResponse
    {
        return $this->applyLock($approvalRule, $request);
    }

    /** Saca el candado. Un admin no puede quitar uno que puso el sistema. */
    public function unlock(Request $request, ApprovalRule $approvalRule): RedirectResponse
    {
        return $this->applyUnlock($approvalRule, $request);
    }

    public function index(Request $request, ApprovalRuleService $service)
    {
        $perPage = (int) $request->get('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200], true) ? $perPage : 25;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'priority_level', 'direction' => 'asc']);
        }

        $isSuper = $request->user()?->hasRole('super') ?? false;

        // El modelo de dominio no declara creator/deleter/tenant: solo se
        // cargan las relaciones que sí existen.
        $reglas = ApprovalRuleService::filter(
            ApprovalRule::query()->select('approval_rules.*')
                ->with(['country:id,name,iso_code', 'workType:id,code']),
            $request,
        )->paginate($perPage)->withQueryString();

        // La etiqueta del rol sale del catálogo: la regla solo guarda el código.
        $etiquetas = ApproverRole::opciones();
        $reglas->getCollection()->transform(function ($regla) use ($etiquetas) {
            $regla->approver_role_label = $etiquetas[$regla->approver_role] ?? $regla->approver_role;

            return $regla;
        });

        $terminos = $request->get('name', []);
        if (is_string($terminos)) {
            $terminos = $terminos === '' ? [] : [$terminos];
        }

        return inertia('ApprovalRules/Index', [
            'approval_rules' => array_merge($reglas->toArray(), [
                'total_unfiltered' => ApprovalRule::count(),
            ]),
            'exportLimits' => \App\Models\Setting::getExportLimits('approval_rules'),
            'options'      => $service->opcionesDelFormulario(),
            // Con el ajuste encendido, el nivel no solo ordena: obliga. Es una
            // diferencia de comportamiento y por eso se avisa en el listado.
            'sequential'   => $service->ordenObligatorio(),
            'filters' => [
                'name'          => array_values($terminos),
                'country_id'    => $request->get('country_id') !== null ? (int) $request->country_id : null,
                'work_type_id'  => $request->get('work_type_id', null),
                'approver_role' => $request->get('approver_role', null),
                'is_required'   => $request->filled('is_required') ? filter_var($request->is_required, FILTER_VALIDATE_BOOLEAN) : null,
                'is_active'     => $request->filled('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : null,
                'created_from'  => $request->get('created_from', ''),
                'created_to'    => $request->get('created_to', ''),
                'sort'          => $request->get('sort', 'priority_level'),
                'direction'     => $request->get('direction', 'asc'),
                'per_page'      => $perPage,
                'advanced_where' => $this->parseAdvancedWhere($request),
            ],
            'isSuper'      => $isSuper,
            'filterSchema' => ApprovalRuleService::filterSchema(),
        ]);
    }

    /**
     * «Para IZAJE quedan estas 4 firmas, en este orden».
     *
     * Resuelve el flujo tipo a tipo con el mismo método que usa la creación de
     * un plan real, así que lo que se enseña aquí es literalmente lo que va a
     * pasar mañana en obra.
     */
    public function preview(Request $request, ApprovalRuleService $service)
    {
        $paises = Country::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'iso_code']);

        // Sin país elegido, se arranca por el del usuario; si tampoco, el primero.
        $countryId = (int) ($request->get('country_id') ?: $request->user()?->country_id ?: $paises->first()?->id);

        return inertia('ApprovalRules/Preview', [
            'countries'  => $paises->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'iso' => $c->iso_code])->all(),
            'countryId'  => $countryId,
            'flows'      => $countryId ? $service->previewPorPais($countryId, $request->user()?->tenant_id) : [],
            'sequential' => $service->ordenObligatorio(),
        ]);
    }

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

    public function show(Request $request, ApprovalRule $approvalRule, ApprovalRuleService $service)
    {
        $approvalRule->load(['country:id,name,iso_code', 'workType:id,code', 'role']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', ApprovalRule::class)
                    ->where('auditable_id', $approvalRule->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        // El flujo completo del que esta regla forma parte: una regla suelta no
        // dice nada; lo que importa es la fila de firmas en la que cae.
        $flujo = $service->previewPorPais($approvalRule->country_id, $approvalRule->tenant_id);
        $suyo  = collect($flujo)->firstWhere('work_type.id', $approvalRule->work_type_id) ?? $flujo[0] ?? null;

        return inertia('ApprovalRules/Show', [
            'approvalRule' => array_merge(
                $this->payload($approvalRule, withAudit: true),
                ['lock' => $this->lockMeta($approvalRule, $request)],
            ),
            'flow'         => $suyo,
            'sequential'   => $service->ordenObligatorio(),
            'recordAudit'  => $this->recordAuditMeta($approvalRule),
            'activity'     => $activity,
        ]);
    }

    public function create(Request $request, ApprovalRuleService $service)
    {
        return inertia('ApprovalRules/Form', [
            'approvalRule' => null,
            'options'      => $service->opcionesDelFormulario(),
            'sequential'   => $service->ordenObligatorio(),
            'defaultCountryId' => $request->user()?->country_id,
        ]);
    }

    public function store(StoreApprovalRuleRequest $request, ApprovalRuleService $service): RedirectResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && ApprovalRule::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.approval_rules.index')
            ->with('success', __('approval_rules.created'));
    }

    public function edit(ApprovalRule $approvalRule, ApprovalRuleService $service)
    {
        // Bloqueado: ni se abre el formulario.
        abort_if($approvalRule->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('ApprovalRules/Form', [
            'approvalRule' => $this->payload($approvalRule),
            'options'      => $service->opcionesDelFormulario(),
            'sequential'   => $service->ordenObligatorio(),
        ]);
    }

    public function update(UpdateApprovalRuleRequest $request, ApprovalRule $approvalRule, ApprovalRuleService $service): RedirectResponse
    {
        // Bloqueado: el formulario no se abre, pero el POST puede llegar igual.
        abort_if($approvalRule->is_locked, 403, __('locks.cannot_edit_locked'));

        $service->update($approvalRule, $request->validated());

        return redirect()
            ->route('business_management.approval_rules.index')
            ->with('success', __('approval_rules.saved'));
    }

    public function delete(ApprovalRule $approvalRule)
    {
        // Bloqueado: ni se abre la confirmacion.
        abort_if($approvalRule->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('ApprovalRules/Delete', [
            'approvalRule' => $this->payload($approvalRule),
            // Cuántos planes ya arrastran esta firma. Borrarla no los toca (la
            // aprobación ya creada sigue ahí), pero conviene saberlo.
            'approvalsCount' => $approvalRule->approvals()->count(),
        ]);
    }

    public function deleteSave(DeleteApprovalRuleRequest $request, ApprovalRule $approvalRule, ApprovalRuleService $service): RedirectResponse
    {
        // Bloqueado: y tampoco por la puerta de atras.
        abort_if($approvalRule->is_locked, 403, __('locks.cannot_delete_locked'));

        $service->delete($approvalRule, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$approvalRule->id]);

        return redirect()
            ->route('business_management.approval_rules.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', ['count' => 1, 'seconds' => 60]);
    }

    protected function storeUndoableDelete(array $ids): void
    {
        session(['approval_rules.recent_delete' => [
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

        $reglas = ApprovalRule::onlyTrashed()
            ->with(['country:id,name', 'workType:id,code'])
            ->when($termino !== '', fn ($q) => $q->where('approver_role', 'like', "%{$termino}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        // Quién borró cada regla, en una consulta y no en N.
        $nombres = \App\Models\User::withTrashed()
            ->whereIn('id', $reglas->pluck('deleted_by')->filter()->unique())
            ->pluck('name', 'id');
        $reglas->getCollection()->transform(function ($regla) use ($nombres) {
            $regla->deleter_name = $nombres[$regla->deleted_by] ?? null;

            return $regla;
        });

        return inertia('ApprovalRules/Trash', [
            'approval_rules' => $reglas,
            'filters' => ['name' => $termino, 'per_page' => $perPage],
        ]);
    }

    public function restore(Request $request, $slug, ApprovalRuleService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $regla = ApprovalRule::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($regla);

        return redirect()
            ->route('business_management.approval_rules.trash')
            ->with('success', __('global.restored_success'));
    }

    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'priority_level', 'direction' => 'asc']);
        }

        $reglas = ApprovalRuleService::filter(
            ApprovalRule::query()->select('approval_rules.*')->with(['country:id,name', 'workType:id,code']),
            $request,
        )->paginate($perPage)->withQueryString();

        $etiquetas = ApproverRole::opciones();
        $reglas->getCollection()->transform(function ($regla) use ($etiquetas) {
            $regla->approver_role_label = $etiquetas[$regla->approver_role] ?? $regla->approver_role;

            return $regla;
        });

        return inertia('ApprovalRules/EditAll', [
            'approval_rules' => $reglas,
            'filters' => [
                'name'      => $request->get('name', ''),
                'is_active' => $request->filled('is_active') ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN) : null,
                'sort'      => $request->get('sort', 'priority_level'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
        ]);
    }

    public function editAllUpdate(EditAllUpdateApprovalRuleRequest $request, ApprovalRuleService $service): RedirectResponse
    {
        $tocadas = $service->editAllUpdate($request->validated()['changes']);

        return redirect()
            ->route('business_management.approval_rules.edit_all')
            ->with('success', __('global.updated_success') . " ({$tocadas})");
    }

    public function bulkRestore(BulkRestoreApprovalRuleRequest $request, ApprovalRuleService $service): RedirectResponse
    {
        $resultado = $service->bulkRestore($request->validated()['ids']);

        if (! empty($resultado['queued'])) {
            return redirect()
                ->route('business_management.approval_rules.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        return redirect()
            ->route('business_management.approval_rules.trash')
            ->with('success', __('global.restored_success') . " ({$resultado['restored']})");
    }

    public function forceDelete(ForceDeleteApprovalRuleRequest $request, $slug, ApprovalRuleService $service): RedirectResponse
    {
        $regla = ApprovalRule::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        // Se confirma escribiendo el código del rol que firma: es lo que
        // identifica la regla a ojo en la papelera.
        if (trim($data['name_confirmation']) !== $regla->approver_role) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($regla, $data['reason']);

        return redirect()
            ->route('business_management.approval_rules.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(ApprovalRule $m, bool $withAudit = false): array
    {
        $etiquetas = ApproverRole::opciones();

        $base = [
            'id'                  => $m->id,
            'slug'                => $m->slug,
            'country_id'          => $m->country_id,
            'country'             => $m->country?->name,
            'work_type_id'        => $m->work_type_id,
            'work_type'           => $m->workType?->code,
            'approver_role'       => $m->approver_role,
            'approver_role_label' => $etiquetas[$m->approver_role] ?? $m->approver_role,
            'priority_level'      => $m->priority_level,
            'is_required'         => (bool) $m->is_required,
            'is_active'           => (bool) $m->is_active,
            'tenant_id'           => $m->tenant_id,
            'created_at'          => $m->created_at,
            'updated_at'          => $m->updated_at,
            'deleted_at'          => $m->deleted_at,
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
        return $this->dispatchExport($request, 'csv', GenerateApprovalRulesCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateApprovalRulesExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateApprovalRulesPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateApprovalRulesWordJob::class);
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

        $limite = \App\Models\Setting::getExportLimit('approval_rules', $formato);
        if ($limite === 0) {
            return;
        }

        $total = $this->countForExport($opciones);
        if ($total > $limite) {
            abort(422, __('approval_rules.export_limit_exceeded', [
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
            return ApprovalRule::query()->count();
        }

        return ApprovalRuleService::filter(ApprovalRule::query(), new Request($opciones['filters'] ?? []))->count();
    }

    // ── Importar ─────────────────────────────────────────────────────────────

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\ApprovalRules\ApprovalRulesImportTemplate(),
            __('approval_rules.import_template_filename')
        );
    }

    public function import(ImportApprovalRuleRequest $request)
    {
        $data   = $request->validated();
        $modo   = $data['mode'] ?? 'update_or_create';
        $dryRun = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('approval_rules.import_super_blocked'),
            ], 422);
        }

        $importador = new \App\Imports\BusinessManagement\ApprovalRules\ApprovalRulesImport(
            mode:   $modo,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importador, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('ApprovalRulesImport failed', ['error' => $e->getMessage()]);

            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => $this->humanizeImportError($e),
            ], 422);
        }

        return response()->json(['ok' => true, 'dry_run' => $dryRun, 'summary' => $importador->summary()], 200);
    }

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

    public function bulkDelete(BulkDeleteApprovalRuleRequest $request, ApprovalRuleService $service): RedirectResponse
    {
        $data = $request->validated();

        // Las bloqueadas se apartan antes de llegar al servicio: una masiva no
        // es sitio para saltarse un candado sin que nadie lo vea.
        [$permitidas, $bloqueadas] = $this->splitLockedIds(ApprovalRule::class, $data['ids']);
        if (empty($permitidas)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueadas)]));
        }

        $resultado = $service->bulkDelete($permitidas, $data['deleted_description']);

        if (! empty($resultado['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        $this->storeUndoableDelete($resultado['deleted']);

        $msg = __('global.deleted_success') . ' (' . $resultado['count'] . ')';
        if (! empty($bloqueadas)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($bloqueadas)]);
        }

        return back()
            ->with('success', $msg)
            ->with('recentDelete', ['count' => $resultado['count'], 'seconds' => 60]);
    }

    public function undoLastDelete(Request $request, ApprovalRuleService $service): RedirectResponse
    {
        $claim = session('approval_rules.recent_delete');
        if (! $claim || ! is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('approval_rules.recent_delete');

            return back()->with('error', __('global.undo_failed'));
        }

        $restauradas = $service->undoLastDelete($claim['ids'], (int) auth()->id());
        session()->forget('approval_rules.recent_delete');

        return empty($restauradas)
            ? back()->with('error', __('global.undo_failed'))
            : back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveApprovalRuleRequest $request, ApprovalRuleService $service): RedirectResponse
    {
        $data = $request->validated();

        [$permitidas, $bloqueadas] = $this->splitLockedIds(ApprovalRule::class, $data['ids']);
        if (empty($permitidas)) {
            return back()->with('error', __('locks.bulk_skipped_locked', ['count' => count($bloqueadas)]));
        }

        $resultado = $service->bulkSetActive($permitidas, (bool) $data['is_active']);

        if (! empty($resultado['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $resultado['count']]));
        }

        $msg = __('global.updated_success') . " ({$resultado['changed']})";
        if (! empty($bloqueadas)) {
            $msg .= ' · ' . __('locks.bulk_skipped_locked', ['count' => count($bloqueadas)]);
        }

        return back()->with('success', $msg);
    }

    // ── Apoyo de exportación ─────────────────────────────────────────────────

    protected function buildExportOptions(Request $request, string $formato): array
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $columnasPermitidas = array_values(array_filter([
            'country', 'work_type', 'approver_role', 'priority_level', 'is_required', 'is_active',
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
            'title'                   => $data['title']                   ?? __('approval_rules.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'landscape',
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
            'auditable_type' => ApprovalRule::class,
            'auditable_id'   => null,
            'module'         => 'approval_rules',
            'old_values'     => null,
            'new_values'     => [
                'format'  => $formato,
                'scope'   => $opciones['scope']   ?? 'filtered',
                'columns' => $opciones['columns'] ?? [],
                'filters' => $opciones['filters'] ?? [],
            ],
            'url'        => route('business_management.approval_rules.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
