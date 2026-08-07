<?php

namespace App\Http\Controllers\BusinessManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessManagement\Customer\BulkDeleteCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\BulkRestoreCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\BulkSetActiveCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\DeleteCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\EditAllUpdateCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\ForceDeleteCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\ImportCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\StoreCustomerRequest;
use App\Http\Requests\BusinessManagement\Customer\UpdateCustomerRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\BusinessManagement\Customers\GenerateCustomersCsvJob;
use App\Jobs\BusinessManagement\Customers\GenerateCustomersExcelJob;
use App\Jobs\BusinessManagement\Customers\GenerateCustomersPdfJob;
use App\Jobs\BusinessManagement\Customers\GenerateCustomersWordJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\BusinessManagement\CustomerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use \App\Traits\BuildsRecordAudit;
    use \App\Http\Controllers\Concerns\HandlesRecordLocking;

    /** Pone el candado al cliente (super → nivel sistema; admin → nivel tenant). */
    public function lock(Request $request, Customer $customer): RedirectResponse
    {
        return $this->applyLock($customer, $request);
    }

    /** Saca el candado (un admin no puede quitar un candado del super). */
    public function unlock(Request $request, Customer $customer): RedirectResponse
    {
        return $this->applyUnlock($customer, $request);
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

        // Solo super necesita el tenant eager-loaded — admins ven solo los suyos
        // y la columna workspace queda oculta en el frontend.
        $with = ['creator:id,name,email', 'country:id,name,iso_code'];
        if ($isSuper) {
            $with[] = 'tenant:id,name';
        }

        $customers = Customer::query()
            ->select('customers.*')
            ->with($with)
            // Conteos de la jerarquía para las columnas del listado. locations,
            // areas (hasManyThrough) y transformers (FK directa) van por withCount;
            // substations (3 niveles) por subquery escalar. Todos respetan el
            // soft-delete (scope global de cada modelo).
            ->withCount(['locations', 'areas', 'transformers'])
            ->addSelect(['substations_count' => \App\Models\CustomerSubstation::query()
                ->selectRaw('count(*)')
                ->whereIn('customer_area_id', \App\Models\CustomerArea::query()
                    ->select('id')
                    ->whereIn('customer_location_id', \App\Models\CustomerLocation::query()
                        ->select('id')
                        ->whereColumn('customer_id', 'customers.id')))])
            ->orderByFavoriteFirst($userId)
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        // Expone el logo_url (accessor) en cada fila del listado.
        $customers->getCollection()->transform(fn ($c) => $c->append('logo_url'));

        $totalUnfiltered = Customer::count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('Customers/Index', [
            'customers' => array_merge($customers->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato — el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('customers'),
            'filters' => [
                'name'         => array_values($names),
                'cod'          => $request->get('cod', ''),
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
            'countryOptions' => $this->countryOptions(),
            'isSuper'        => $isSuper,
            // Usuario acotado a clientes asignados: no puede crear/duplicar (el
            // front oculta esos botones; el backend ya lo bloquea igual).
            'isCustomerRestricted' => !empty($request->user()?->assignedCustomerIds()),
            // Schema de campos filtrables — alimenta el drawer "Filtros
            // avanzados" del frontend (selects de field/op + control tipado
            // del valor). Cada modulo declara el suyo en su modelo.
            'filterSchema'   => Customer::filterSchema(['countries' => $this->countryOptions()]),
        ]);
    }

    /**
     * Normaliza `advanced_where` del request: viene como JSON string o array
     * directo segun como Inertia lo serialice. Lista PLANA de cláusulas
     * {field, op, value, conj?} (estilo RENATI); descarta las incompletas y
     * conserva el conector 'conj' por cláusula. Persistido para que al recargar
     * (paginate, sort) el filtro siga aplicado.
     */
    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (!is_array($raw)) return [];

        $out = [];
        foreach ($raw as $r) {
            if (is_array($r) && !empty($r['field']) && !empty($r['op'])) {
                $out[] = $r;
            }
        }
        return array_values($out);
    }

    public function show(Request $request, Customer $customer)
    {
        $customer->load(['creator:id,name,email', 'deleter:id,name,email', 'locker:id,name']);

        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit ? $this->customerActivity($customer) : [];

        return inertia('Customers/Show', [
            'customer' => array_merge(
                $this->payload($customer, withAudit: true),
                ['lock' => $this->lockMeta($customer, $request)],
            ),
            'activity'     => $activity,
            'recordAudit'  => $this->recordAuditMeta($customer),
            'hierarchy'    => $this->hierarchyTree($customer),
            // Usuario acotado a su cartera: solo-lectura (oculta editar/eliminar).
            'isCustomerRestricted' => !empty($request->user()?->assignedCustomerIds()),
        ]);
    }

    /**
     * Feed de auditoría del cliente: incluye los eventos del propio cliente Y de
     * toda su jerarquía (ubicaciones/áreas/subestaciones), para que "Cambios"
     * muestre, p. ej., "creó la Ubicación «Sede Norte»" o "eliminó el Área «X»".
     * Cada item lleva un `subject` (tipo + nombre) para dar contexto.
     */
    protected function customerActivity(Customer $customer): array
    {
        $locIds  = \App\Models\CustomerLocation::withTrashed()->where('customer_id', $customer->id)->pluck('id');
        $areaIds = \App\Models\CustomerArea::withTrashed()->whereIn('customer_location_id', $locIds)->pluck('id');
        $subIds  = \App\Models\CustomerSubstation::withTrashed()->whereIn('customer_area_id', $areaIds)->pluck('id');

        $labels = [
            Customer::class                       => __('customers.singular'),
            \App\Models\CustomerLocation::class    => __('customers.location'),
            \App\Models\CustomerArea::class        => __('customers.area'),
            \App\Models\CustomerSubstation::class  => __('customers.substation'),
        ];

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->where(function ($q) use ($customer, $locIds, $areaIds, $subIds) {
                $q->where(fn ($w) => $w->where('auditable_type', Customer::class)->where('auditable_id', $customer->id))
                  ->orWhere(fn ($w) => $w->where('auditable_type', \App\Models\CustomerLocation::class)->whereIn('auditable_id', $locIds))
                  ->orWhere(fn ($w) => $w->where('auditable_type', \App\Models\CustomerArea::class)->whereIn('auditable_id', $areaIds))
                  ->orWhere(fn ($w) => $w->where('auditable_type', \App\Models\CustomerSubstation::class)->whereIn('auditable_id', $subIds));
            })
            ->orderByDesc('created_at')
            ->limit(40)
            ->get(['id', 'user_id', 'auditable_type', 'auditable_id', 'event', 'old_values', 'new_values', 'created_at']);

        return $logs->map(function ($log) use ($labels, $customer) {
            // El sujeto del cliente raíz no necesita nombre (ya es el contexto).
            $isRoot = $log->auditable_type === Customer::class;
            $name = $log->new_values['name'] ?? $log->old_values['name'] ?? null;
            $subject = $isRoot
                ? null
                : trim(($labels[$log->auditable_type] ?? '') . ($name ? " «{$name}»" : ''));

            // Reusa el shape humanizado del Resource (incluye `changes`) y le
            // suma el `subject` de jerarquía propio de Customer.
            return array_merge(
                (new AuditLogResource($log))->resolve(),
                ['subject' => $subject],
            );
        })->all();
    }

    /**
     * Árbol de la jerarquía del cliente + totales por nivel, para el dashboard.
     * Estructura recursiva uniforme (type/id/slug/name/children) — el árbol del
     * frontend la renderiza genéricamente, sin lógica por nivel.
     */
    protected function hierarchyTree(Customer $customer): array
    {
        $customer->load([
            'locations.areas.substations.transformers' => fn ($q) => $q->orderBy('serial'),
        ]);

        $totalAreas = 0; $totalSubs = 0; $totalTrafos = 0;
        $nodes = [];

        foreach ($customer->locations as $loc) {
            $areas = [];
            foreach ($loc->areas as $ar) {
                $totalAreas++;
                $subs = [];
                foreach ($ar->substations as $su) {
                    $totalSubs++;
                    $trafos = [];
                    foreach ($su->transformers as $tr) {
                        $totalTrafos++;
                        $trafos[] = [
                            'type' => 'transformer', 'id' => $tr->id, 'slug' => $tr->slug,
                            'name' => $tr->serial ?: $tr->name,
                            'health_index' => $tr->health_index, 'health_rating' => $tr->health_rating,
                        ];
                    }
                    $subs[] = [
                        'type' => 'substation', 'id' => $su->id, 'slug' => $su->slug,
                        'name' => $su->name, 'count' => count($trafos), 'children' => $trafos,
                    ];
                }
                $areas[] = ['type' => 'area', 'id' => $ar->id, 'slug' => $ar->slug, 'name' => $ar->name, 'children' => $subs];
            }
            $nodes[] = ['type' => 'location', 'id' => $loc->id, 'slug' => $loc->slug, 'name' => $loc->name, 'children' => $areas];
        }

        return [
            'nodes'  => $nodes,
            'totals' => [
                'locations'    => $customer->locations->count(),
                'areas'        => $totalAreas,
                'substations'  => $totalSubs,
                'transformers' => $totalTrafos,
            ],
        ];
    }

    public function create()
    {
        return inertia('Customers/Form', [
            'customer'        => null,
            'countryOptions'  => $this->countryOptions(),
            // Al crear, el país viene por defecto del país del usuario (editable).
            'defaultCountryId' => auth()->user()?->country_id,
            'tenantOptions'   => $this->tenantOptions(),
        ]);
    }

    public function store(StoreCustomerRequest $request, CustomerService $service): RedirectResponse
    {
        // Limite de registros por modulo segun el plan del tenant.
        // super no tiene tenant → no aplica. -1 = ilimitado.
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Customer::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $customer = $service->create($request->validated(), $request->file('logo'));

        // Tras crear, ir al "ver" del cliente (ya trae su jerarquía por defecto).
        return redirect()
            ->route('business_management.customers.show', $customer->slug)
            ->with('success', __('customers.created'));
    }

    /**
     * Alta rápida de cliente desde otro formulario (ej. el de transformadores):
     * valida con las mismas reglas, respeta el límite del plan y devuelve JSON
     * {id, name} para que el front lo agregue al Select y lo seleccione.
     */
    public function quickStore(StoreCustomerRequest $request, CustomerService $service): \Illuminate\Http\JsonResponse
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Customer::count() >= $max) {
                return response()->json(['message' => __('plans.limit_records_reached', ['max' => $max])], 422);
            }
        }

        $customer = $service->create($request->validated(), null);

        return response()->json(['id' => $customer->id, 'name' => $customer->name], 201);
    }

    public function edit(Customer $customer)
    {
        // Registro bloqueado (Lockable): ni se abre el formulario de edición.
        abort_if($customer->is_locked, 403, __('locks.cannot_edit_locked'));

        return inertia('Customers/Form', [
            'customer'        => $this->payload($customer),
            'countryOptions'  => $this->countryOptions(),
            'tenantOptions'   => $this->tenantOptions(),
        ]);
    }

    /** Workspaces activos como Select options — solo para super (vacío si no). */
    protected function tenantOptions(): array
    {
        if (! (auth()->user()?->hasRole('super') ?? false)) return [];
        return \App\Models\Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->all();
    }

    /** Países activos como Select options. */
    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'iso_code'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ' (' . $c->iso_code . ')'])
            ->all();
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        $service->update($customer, $request->validated(), $request->file('logo'));

        return redirect()
            ->route('business_management.customers.index')
            ->with('success', __('customers.saved'));
    }

    public function delete(Customer $customer)
    {
        // Registro bloqueado (Lockable): ni se abre la confirmación de borrado.
        abort_if($customer->is_locked, 403, __('locks.cannot_delete_locked'));

        return inertia('Customers/Delete', [
            'customer' => $this->payload($customer),
        ]);
    }

    public function deleteSave(DeleteCustomerRequest $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        $service->delete($customer, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$customer->id]);

        return redirect()
            ->route('business_management.customers.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$customer->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['customers.recent_delete' => [
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

        $customers = Customer::onlyTrashed()
            ->with('deleter:id,name,email')
            ->when($name !== '', fn ($q) => $q->where('name', 'like', "%{$name}%"))
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Customers/Trash', [
            'customers' => $customers,
            'filters'   => [
                'name'     => $name,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, CustomerService $service): RedirectResponse
    {
        abort_unless($request->user()?->hasRole('super'), 403);
        $model = Customer::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($model);

        return redirect()
            ->route('business_management.customers.trash')
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

        $customers = Customer::query()
            ->filter($request)
            ->select('customers.id', 'customers.slug', 'customers.name', 'customers.cod', 'customers.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Customers/EditAll', [
            'customers' => $customers,
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

    public function editAllUpdate(EditAllUpdateCustomerRequest $request, CustomerService $service): RedirectResponse
    {
        $changes = $request->validated()['changes'];

        // Excluir registros BLOQUEADOS (Lockable) de la edición masiva.
        $ids = array_column($changes, 'id');
        [, $lockedIds] = $this->splitLockedIds(Customer::class, $ids);
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
            ->route('business_management.customers.edit_all')
            ->with('success', $msg);
    }

    /**
     * Clona el customer. Sufijo "(copia)" con sanity guard de 100 intentos.
     */
    public function duplicate(Request $request, Customer $customer, CustomerService $service): RedirectResponse
    {
        // Mismo criterio que crear: un usuario restringido a clientes asignados
        // no duplica (el clon quedaría huérfano, no asignado a él).
        if (! empty($request->user()?->assignedCustomerIds())) {
            return back()->with('error', __('customers.restricted_no_create'));
        }

        // Duplicar crea un registro nuevo → respeta el límite del plan (como store).
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            $max = $tenant->maxRecordsPerModule();
            if ($max > 0 && Customer::count() >= $max) {
                return back()->with('error', __('plans.limit_records_reached', ['max' => $max]));
            }
        }

        $clone = $service->duplicate($customer);

        if (!$clone) {
            return back()->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('business_management.customers.index')
            ->with('success', __('global.duplicated_success'));
    }

    public function bulkRestore(BulkRestoreCustomerRequest $request, CustomerService $service): RedirectResponse
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if (!empty($result['queued'])) {
            return redirect()
                ->route('business_management.customers.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('business_management.customers.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    public function forceDelete(ForceDeleteCustomerRequest $request, $slug, CustomerService $service): RedirectResponse
    {
        $model = Customer::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data  = $request->validated();

        if (trim($data['name_confirmation']) !== $model->name) {
            return back()->withErrors(['name_confirmation' => __('global.force_delete_name_mismatch')]);
        }

        $service->forceDelete($model, $data['reason']);

        return redirect()
            ->route('business_management.customers.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    protected function payload(Customer $m, bool $withAudit = false): array
    {
        $m->loadMissing('country:id,name,iso_code');
        $base = [
            'id'         => $m->id,
            'slug'       => $m->slug,
            'name'       => $m->name,
            'cod'        => $m->cod,
            'address'    => $m->address,
            'logo_url'   => $m->logo_url,
            'country_id' => $m->country_id,
            'country'    => $m->country ? [
                'id'       => $m->country->id,
                'name'     => $m->country->name,
                'iso_code' => $m->country->iso_code,
            ] : null,
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
        return $this->dispatchExport($request, 'csv', GenerateCustomersCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateCustomersExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateCustomersPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateCustomersWordJob::class);
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

        $limit = \App\Models\Setting::getExportLimit('customers', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('customers.export_limit_exceeded', [
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
            return Customer::query()->count();
        }
        // Filters como Request para reusar scopeFilter.
        $fakeReq = new Request($options['filters'] ?? []);
        return Customer::query()->filter($fakeReq)->count();
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\BusinessManagement\Customers\CustomersImportTemplate(),
            __('customers.import_template_filename')
        );
    }

    public function import(ImportCustomerRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Guardrail multi-tenant: super NO puede importar sin tenant porque
        // el lookup por nombre case-insensitive matchearía customers de cualquier
        // workspace y los update cross-tenant. Si super necesita importar a un
        // tenant específico, debe loguearse impersonando el admin de ese tenant
        // o usar la API directamente con `Auth::onceUsingId(...)`.
        $user = $request->user();
        if ($user && $user->hasRole('super') && empty($user->tenant_id)) {
            return response()->json([
                'ok'      => false,
                'dry_run' => $dryRun,
                'message' => __('customers.import_super_blocked', [], 'Super sin workspace asignado no puede importar — el match por nombre puede actualizar registros de otro tenant.'),
            ], 422);
        }

        $importer = new \App\Imports\BusinessManagement\Customers\CustomersImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('CustomersImport failed', [
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
    public function bulkDelete(BulkDeleteCustomerRequest $request, CustomerService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no se borran en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Customer::class, $data['ids']);
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
    public function undoLastDelete(Request $request, CustomerService $service): RedirectResponse
    {
        $claim = session('customers.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('customers.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $restored = $service->undoLastDelete($claim['ids'], (int) auth()->id());

        if (empty($restored)) {
            session()->forget('customers.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        session()->forget('customers.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    public function bulkSetActive(BulkSetActiveCustomerRequest $request, CustomerService $service): RedirectResponse
    {
        $data = $request->validated();

        // Excluir registros BLOQUEADOS (Lockable): no cambian de estado en masa.
        [$allowedIds, $lockedIds] = $this->splitLockedIds(Customer::class, $data['ids']);
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

    // ── Export helpers ──────────────────────────────────────────────────

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyeccion de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        // Sin 'id' (no se exporta). La columna `tenant` (workspace) SOLO es
        // exportable por super: el resto ve únicamente clientes de su propio
        // tenant. Gate de seguridad real (no basta ocultarla en el front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'name', 'cod', 'country', 'address', 'is_active',
            'locations_count', 'areas_count', 'substations_count', 'transformers_count',
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
            'title'                   => $data['title']                   ?? __('customers.export_title'),
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
            'auditable_type' => Customer::class,
            'auditable_id'   => null,
            'module'         => 'customers',
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
            'url'        => route('business_management.customers.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
