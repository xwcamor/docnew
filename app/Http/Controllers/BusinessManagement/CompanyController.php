<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Company\BulkDeleteCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\BulkRestoreCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\BulkSetActiveCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\DeleteCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\EditAllUpdateCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\ForceDeleteCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\ImportCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\StoreCompanyRequest;
use App\Http\Requests\BusinessManagement\Company\UpdateCompanyRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\Companies\GenerateCompaniesCsvJob;
use App\Jobs\BusinessManagement\Companies\GenerateCompaniesExcelJob;
use App\Jobs\BusinessManagement\Companies\GenerateCompaniesPdfJob;
use App\Jobs\BusinessManagement\Companies\GenerateCompaniesWordJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\BusinessManagement\CompanyService;
use App\Support\LikeQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /** Pone el candado a la marca (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, Company $company): RedirectResponse
    {
        return $this->applyLock($company, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, Company $company): RedirectResponse
    {
        return $this->applyUnlock($company, $request);
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

        // companies es per-tenant (BelongsToTenant lo scopea solo) — eager-load creator.
        // El super ve cross-tenant: carga el tenant para mostrarlo en el drawer.
        $with = ['creator:id,name,email', 'country:id,name,iso_code'];
        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        $companies = Company::query()
            ->select('companies.*')
            ->with($with)
            // Cuánta gente tiene vinculada y cuántos planes ha ejecutado: es lo
            // que distingue una contratista activa de una dada de alta y olvidada.
            ->withCount(['people', 'workPlans'])
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        $totalUnfiltered = Company::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('Companies/Index', [
            'companies' => array_merge($companies->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato â€” el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('companies'),
            'filters' => [
                'name'         => array_values($names),
                'num_doc'      => $request->get('num_doc', ''),
                'country_id'   => $request->get('country_id', []),
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
            'countryOptions' => $this->countryOptions(),
            // Schema de campos filtrables â€” alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            'filterSchema'   => Company::filterSchema(['countries' => $this->countryOptions()]),
        ]);
    }

    /** Países activos como Select options (filtros y formulario). */
    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
            ->all();
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

    public function show(Request $request, Company $company)
    {
        $company->load(['creator:id,name,email', 'deleter:id,name,email', 'locker:id,name', 'country:id,name,iso_code'])
                ->loadCount(['people', 'workPlans']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Company::class)
                    ->where('auditable_id', $company->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'auditable_type', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Companies/Show', [
            'company' => array_merge(
                $this->payload($company, withAudit: true),
                ['lock' => $this->lockMeta($company, $request)],
            ),
            'recordAudit'  => $this->recordAuditMeta($company),
            'activity'     => $activity,
        ]);
    }

    public function create()
    {
        return inertia('Companies/Form', [
            'company'          => null,
            'countryOptions'   => $this->countryOptions(),
            // Al crear se propone el país del usuario: casi siempre es el suyo.
            'defaultCountryId' => request()->user()?->country_id,
        ]);
    }

    public function store(StoreCompanyRequest $request, CompanyService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant â†’ no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Company::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $service->create($request->validated());

        return redirect()
            ->route('business_management.companies.index')
            ->with('success', __('companies.created'));
    }

    /**
     * Alta rápida de marca desde un select de otro módulo (ej. el form de
     * trafos) sin salir de la página. Misma validación que store() — incluye
     * unicidad insensible a acentos/mayúsculas, así que bloquea duplicados —
     * pero responde JSON con la marca creada para inyectarla en el select.
     * Gated por permission:companies.create (super/admin pasan por sus permisos).
     */
    public function quickStore(StoreCompanyRequest $request, CompanyService $service): \Illuminate\Http\JsonResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Company::count() >= $max) {
                return response()->json(['message' => __('plans.limit_records_reached', ['max' => $max])], 422);
            }
        }

        $company = $service->create($request->validated());

        return response()->json(['id' => $company->id, 'name' => $company->name], 201);
    }

    public function edit(Company $company)
    {
        // Registro bloqueado (Lockable): ni se abre el formulario de edición.
        abort_if($company->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('Companies/Form', [
            'company'          => $this->payload($company),
            'countryOptions'   => $this->countryOptions(),
            'defaultCountryId' => request()->user()?->country_id,
        ]);
    }


    public function update(UpdateCompanyRequest $request, Company $company, CompanyService $service): RedirectResponse
    {
        $service->update($company, $request->validated());

        return redirect()
            ->route('business_management.companies.index')
            ->with('success', __('companies.saved'));
    }

    public function delete(Company $company, CompanyService $service)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($company->is_locked, 403, __('locks.cannot_delete_locked'));

        // La confirmación muestra razón social y país: hay contratistas con
        // nombres cortos casi idénticos.
        $company->load('country:id,name,iso_code')->loadCount(['people', 'workPlans']);

        return inertia('Companies/Delete', [
            'company' => $this->payload($company),
            // Lo que cuelga de la empresa, dicho ANTES de pedir el motivo: si
            // hay planes o gente, el botón sale deshabilitado y explica por qué
            // (docs/UI.md §6) en vez de fallar al pulsarlo.
            'dependents'    => $company->countDependents(),
            'blockedReason' => $service->motivoParaNoBorrar($company),
        ]);
    }

    public function deleteSave(DeleteCompanyRequest $request, Company $company, CompanyService $service): RedirectResponse
    {
        try {
            $service->delete($company, $request->validated()['deleted_description']);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->storeUndoableDelete([$company->id]);

        return redirect()
            ->route('business_management.companies.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$company->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['companies.recent_delete' => [
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

        // La papelera enseña nombre corto, razón social y RUC: el buscador mira
        // los tres, igual que el del listado. Antes solo miraba el nombre corto
        // y escribir el RUC no devolvía nada.
        // Y mira igual que el del listado: `unaccent(lower(...))`. Con el LIKE
        // pelado que habia aqui, en Postgres «AUDIT» encontraba la empresa y
        // «audit» no devolvia nada — la misma busqueda daba un resultado u otro
        // segun como estuviera el bloqueo de mayusculas.
        $isPgsql = Company::query()->getConnection()->getDriverName() === 'pgsql';
        $needle  = LikeQuery::contains((string) $name);
        $ruc     = LikeQuery::contains(preg_replace('/[\s-]/', '', (string) $name));

        $companies = Company::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($name !== '', fn ($q) => $q->where(function ($qq) use ($isPgsql, $needle, $ruc) {
                if ($isPgsql) {
                    $qq->orWhereRaw('unaccent(lower(companies.name)) LIKE unaccent(lower(?))', [$needle]);
                    $qq->orWhereRaw('unaccent(lower(companies.complete_name)) LIKE unaccent(lower(?))', [$needle]);
                } else {
                    $qq->orWhereRaw("companies.name LIKE ? ESCAPE '\\'", [$needle]);
                    $qq->orWhereRaw("companies.complete_name LIKE ? ESCAPE '\\'", [$needle]);
                }
                $qq->orWhereRaw(
                    $isPgsql ? 'companies.num_doc LIKE ?' : "companies.num_doc LIKE ? ESCAPE '\\'",
                    [$ruc],
                );
            }))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Companies/Trash', [
            'companies' => $companies,
            'filters'   => [
                'name'     => $name,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, CompanyService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = Company::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.companies.trash')
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

        $companies = Company::query()
            ->filter($request)
            ->select('companies.id', 'companies.slug', 'companies.name', 'companies.num_doc', 'companies.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Companies/EditAll', [
            'companies' => $companies,
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

    public function editAllUpdate(EditAllUpdateCompanyRequest $request, CompanyService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(Company::class, $ids);
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
            ->route('business_management.companies.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el company. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, Company $company, CompanyService $service): RedirectResponse
    {
        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Company::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($company);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.companies.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreCompanyRequest $request, CompanyService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.companies.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.companies.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteCompanyRequest $request, $slug, CompanyService $service): RedirectResponse
    {
        $model = Company::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        try {
            $service->forceDelete($model, $data['reason']);
        } catch (\DomainException $e) {
            // Planes o gente colgando: la clave ajena es `restrictOnDelete`, asi
            // que sin este aviso el super se comia un error de base de datos.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('business_management.companies.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(Company $m, bool $withAudit = false): array
    {
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'name'       => $m->name,
            'num_doc'       => $m->num_doc,
            'complete_name' => $m->complete_name,
            'country_id' => $m->country_id,
            'country'    => $m->relationLoaded('country') && $m->country
                ? ['id' => $m->country->id, 'name' => $m->country->name, 'iso_code' => $m->country->iso_code]
                : null,
            'people_count'     => $m->people_count,
            'work_plans_count' => $m->work_plans_count,
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
        return $this->dispatchExport($request, 'csv', GenerateCompaniesCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateCompaniesExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateCompaniesPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateCompaniesWordJob::class);
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

        $limit = \App\Models\Setting::getExportLimit('companies', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('companies.export_limit_exceeded', [
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
            return Company::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return Company::query()->filter($fakeReq)->count();
    }

    // â”€â”€ IMPORTS (two-phase: dry_run preview + commit) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\Companies\CompaniesImportTemplate(),
            __('companies.import_template_filename')
        );
    }

    public function import(ImportCompanyRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearÃ­a companies de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant especÃ­fico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('companies.import_super_blocked'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\Companies\CompaniesImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('CompaniesImport failed', [
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
    public function bulkDelete(BulkDeleteCompanyRequest $request, CompanyService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Company::class, $data['ids']);
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
        $inUseIds   = $result['in_use'] ?? [];

        // Ninguna se pudo borrar: es un aviso, no un exito con cero.
        if (empty($deletedIds)) {
            return back()->with('error', __('companies.bulk_skipped_in_use', ['count' => count($inUseIds)]));
        }

        $this->storeUndoableDelete($deletedIds);

        $msg = __('global.deleted_success') . ' (' . count($deletedIds) . ')';
        if (!empty($inUseIds)) {
            $msg .= ' · ' . __('companies.bulk_skipped_in_use', ['count' => count($inUseIds)]);
        }
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
    public function undoLastDelete(Request $request, CompanyService $service): RedirectResponse
    {
        $claim = session('companies.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('companies.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('companies.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('companies.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveCompanyRequest $request, CompanyService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no cambian de estado en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Company::class, $data['ids']);
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
        // exportable por super: el resto ve únicamente marcas de su propio
        // tenant. Gate de seguridad real (no basta ocultarla en el front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'name', 'num_doc', 'complete_name', 'country', 'people_count', 'work_plans_count', 'is_active',
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
            'title'                   => $data['title']                   ?? __('companies.export_title'),
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
            'auditable_type' => Company::class,
            'auditable_id'   => null,
            'module'         => 'companies',
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
            'url'        => route('business_management.companies.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
