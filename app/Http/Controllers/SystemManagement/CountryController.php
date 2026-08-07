<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Http\Requests\SystemManagement\Country\StoreRequest;
use App\Http\Requests\SystemManagement\Country\UpdateRequest;
use App\Http\Requests\SystemManagement\Country\DeleteRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Requests\SystemManagement\Country\BulkDeleteRequest;
use App\Http\Requests\SystemManagement\Country\BulkSetActiveRequest;
use App\Http\Requests\SystemManagement\Country\BulkRestoreRequest;
use App\Http\Requests\SystemManagement\Country\ForceDeleteRequest;
use App\Http\Requests\SystemManagement\Country\EditAllUpdateRequest;
use App\Http\Requests\SystemManagement\Country\ImportRequest;
use App\Services\SystemManagement\CountryService;
use Illuminate\Http\Request;

/**
 * Permisos: todas las rutas viven dentro de `role:super` middleware en
 * routes/system_management.php. Por eso NO chequeamos permission:countries.*
 * por método — sería redundante. Al clonar a módulos per-tenant, mover las
 * rutas fuera de ese grupo y aplicar `permission:patients.edit` etc.
 *
 * Acciones críticas (force_delete, bulk_restore, restore, undo_last_delete)
 * tienen su propio abort_unless(super) como defense-in-depth.
 */
class CountryController extends Controller
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
        $countries = Country::filter($request)
            ->select(
                'countries.id', 'countries.slug', 'countries.name',
                'countries.iso_code', 'countries.currency', 'countries.timezone',
                'countries.region_id', 'countries.default_locale_id',
                'countries.is_active', 'countries.created_at', 'countries.updated_at', 'countries.created_by'
            )
            ->with([
                'creator:id,name,email',
                'region:id,name',
                'defaultLocale:id,code,name',
            ])
            ->orderByFavoriteFirst($userId)
            ->paginate($perPage)
            ->withQueryString();

        // is_favorite viene como int 0/1 o bool según driver — normalizar.
        $countries->getCollection()->transform(function ($r) {
            $r->is_favorite = (bool) ($r->is_favorite ?? false);
            return $r;
        });

        $totalUnfiltered = Country::count();

        // 'name' puede venir como string o array — normalizamos a array.
        $names = $request->get('name', []);
        if (is_string($names)) {
            $names = $names === '' ? [] : [$names];
        }

        return inertia('Countries/Index', [
            'countries' => array_merge($countries->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Límites de export por formato — el frontend deshabilita formatos
            // que exceden su límite. CSV con 0 = sin límite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('countries'),
            'filters' => [
                'name'         => array_values($names),
                'iso_code'     => $request->get('iso_code', []),
                'currency'     => $request->get('currency', []),
                'region_id'    => $request->get('region_id', []),
                'default_locale_id' => $request->get('default_locale_id', []),
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
            'regionOptions' => $this->regionOptions(),
            'localeOptions' => $this->localeOptions(),
            'filterSchema'  => Country::filterSchema(),
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

    public function create()
    {
        return inertia('Countries/Form', [
            'country' => null,
            'regionOptions' => $this->regionOptions(),
            'localeOptions' => $this->localeOptions(),
        ]);
    }

    /**
     * Catálogos para los selectores cascada del Form e Index.
     * Solo registros activos (no soft-deleted). Si la lista crece >1000,
     * convertir a endpoint AJAX paginated.
     */
    protected function regionOptions(): array
    {
        return \App\Models\Region::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($r) => ['value' => $r->id, 'label' => $r->name])
            ->all();
    }

    protected function localeOptions(): array
    {
        return \App\Models\Locale::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn($l) => ['value' => $l->id, 'label' => $l->code . ' — ' . $l->name])
            ->all();
    }

    public function store(StoreRequest $request, CountryService $service)
    {
        $service->create($request->validated());

        return redirect()
            ->route('system_management.countries.index')
            ->with('success', __('global.created_success'));
    }

    /**
     * Solo super ve countryes soft-deleted (con motivo + deleter).
     * Otros usuarios reciben 404 — privacidad: no exponemos que existió.
     */
    public function show(Request $request, $slug)
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = Country::with([
            'creator:id,name,email',
            'deleter:id,name,email',
            'region:id,name',
            'defaultLocale:id,code,name',
        ]);
        if ($isSuper) {
            $query->withTrashed();
        }

        $country = $query->where('slug', $slug)->firstOrFail();

        // Track recent view (best-effort, no rompe la pantalla si falla).
        if ($userId = $request->user()?->id) {
            try {
                \App\Models\UserRecentView::track($userId, Country::class, $country->id);
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
                    ->where('auditable_type', \App\Models\Country::class)
                    ->where('auditable_id', $country->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Countries/Show', [
            'country' => [
                'id'                  => $country->id,
                'slug'                => $country->slug,
                'name'                => $country->name,
                'iso_code'            => $country->iso_code,
                'currency'            => $country->currency,
                'timezone'            => $country->timezone,
                'region_id'           => $country->region_id,
                'default_locale_id'   => $country->default_locale_id,
                'is_active'           => $country->is_active,
                'created_at'          => $country->created_at,
                'updated_at'          => $country->updated_at,
                'deleted_at'          => $country->deleted_at,
                'deleted_description' => $country->deleted_description,
                'region' => $country->region ? [
                    'id'   => $country->region->id,
                    'name' => $country->region->name,
                ] : null,
                'default_locale' => $country->defaultLocale ? [
                    'id'   => $country->defaultLocale->id,
                    'code' => $country->defaultLocale->code,
                    'name' => $country->defaultLocale->name,
                ] : null,
                'creator' => $country->creator ? [
                    'id'    => $country->creator->id,
                    'name'  => $country->creator->name,
                    'email' => $country->creator->email,
                ] : null,
                'deleter' => $country->deleter ? [
                    'id'    => $country->deleter->id,
                    'name'  => $country->deleter->name,
                    'email' => $country->deleter->email,
                ] : null,
            ],
            'recordAudit' => $this->recordAuditMeta($country),
            'activity' => $activity,
        ]);
    }

    public function edit(Country $country)
    {
        return inertia('Countries/Form', [
            'country' => [
                'id'                => $country->id,
                'slug'              => $country->slug,
                'name'              => $country->name,
                'iso_code'          => $country->iso_code,
                'currency'          => $country->currency,
                'timezone'          => $country->timezone,
                'region_id'         => $country->region_id,
                'default_locale_id' => $country->default_locale_id,
                'is_active'         => $country->is_active,
            ],
            'regionOptions' => $this->regionOptions(),
            'localeOptions' => $this->localeOptions(),
        ]);
    }

    public function update(UpdateRequest $request, Country $country, CountryService $service)
    {
        $service->update($country, $request->validated());

        return redirect()
            ->route('system_management.countries.index')
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

        $countries = Country::onlyTrashed()
            ->with(['deleter:id,name,email'])
            ->select('countries.id', 'countries.slug', 'countries.name', 'countries.is_active', 'countries.deleted_at', 'countries.deleted_by', 'countries.deleted_description', 'countries.created_at')
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Countries/Trash', [
            'countries' => $countries,
            'filters' => [
                'name'     => $request->get('name', ''),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, string $slug, CountryService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $country = Country::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($country);

        return redirect()
            ->route('system_management.countries.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(BulkRestoreRequest $request, CountryService $service)
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.countries.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.countries.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    /**
     * Hard-delete con triple guard: super + onlyTrashed + nombre exacto.
     * Audit log se escribe ANTES del delete físico (sobrevive al borrado).
     */
    public function forceDelete(ForceDeleteRequest $request, string $slug, CountryService $service)
    {
        $country = Country::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data   = $request->validated();

        if (trim($data['name_confirmation']) !== $country->name) {
            return back()->withErrors([
                'name_confirmation' => __('global.force_delete_name_mismatch'),
            ]);
        }

        $service->forceDelete($country, $data['reason']);

        return redirect()
            ->route('system_management.countries.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ── SOFT-DELETE + UNDO + DUPLICATE ─────────────────────────────────────

    public function delete(Country $country)
    {
        return inertia('Countries/Delete', [
            'country' => [
                'id'        => $country->id,
                'slug'      => $country->slug,
                'name'      => $country->name,
                'is_active' => $country->is_active,
            ],
            'dependents' => $country->countDependents(),
        ]);
    }

    public function deleteSave(DeleteRequest $request, Country $country, CountryService $service)
    {
        // Para Country/countries block=false → no bloquea aquí. El patrón sirve
        // para módulos donde sí cuente (ej. doctors con appointments futuros).
        if ($country->hasBlockingDependents()) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $service->delete($country, $request->deleted_description);

        // Claim de undo en sesión (60s). La lógica de undo valida sesión,
        // no rol — quien eliminó puede deshacer aunque no sea super.
        $this->storeUndoableDelete([$country->id]);

        return redirect()
            ->route('system_management.countries.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$country->id]));
    }

    /**
     * Clona la región. El service maneja la generación del sufijo "(copia)"
     * con sanity guard. Si devuelve null → 100 intentos agotados (raro).
     */
    public function duplicate(Request $request, Country $country, CountryService $service)
    {
        $clone = $service->duplicate($country);

        if (!$clone) {
            return redirect()
                ->route('system_management.countries.index')
                ->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('system_management.countries.index')
            ->with('success', __('global.duplicated_success'));
    }

    // ── BULK OPERATIONS ─────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteRequest $request, CountryService $service)
    {
        $data = $request->validated();

        // Service maneja threshold + dependency check + delete loop. No
        // ofrecemos undo en async: el delete real ocurre después del
        // redirect y el window de 60s no calza con un job que tarda minutos.
        $result = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.countries.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        if (!empty($result['blocked'])) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $this->storeUndoableDelete($result['deleted_ids']);

        return redirect()
            ->route('system_management.countries.index')
            ->with('success', __('global.deleted_success') . " ({$result['count']})")
            ->with('recentDelete', $this->buildRecentDeletePayload($result['deleted_ids']));
    }

    /**
     * Undo dentro del window de 60s. Validamos contra session claim (no rol):
     * quien eliminó puede deshacer su propio error sin ser super.
     * Defense in depth: además exigimos `deleted_by = current_user`.
     */
    public function undoLastDelete(Request $request, CountryService $service)
    {
        $claim = session('countries.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('countries.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $userId = auth()->id();
        $countries = Country::onlyTrashed()
            ->whereIn('id', $claim['ids'])
            ->where('deleted_by', $userId)
            ->get();

        if ($countries->isEmpty()) {
            session()->forget('countries.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        foreach ($countries as $country) {
            $service->restore($country);
        }
        session()->forget('countries.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    /** Persiste el claim en sesión por el window configurado para validar el undo. */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['countries.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds((int) config('countries.undo_window_seconds', 60)),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => (int) config('countries.undo_window_seconds', 60),
        ];
    }

    public function bulkSetActive(BulkSetActiveRequest $request, CountryService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.countries.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.countries.index')
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

        $countries = Country::filter($request)
            ->select(
                'countries.id', 'countries.slug', 'countries.name',
                'countries.iso_code', 'countries.currency', 'countries.timezone',
                'countries.region_id', 'countries.default_locale_id',
                'countries.is_active'
            )
            ->with(['region:id,name', 'defaultLocale:id,code'])
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Countries/EditAll', [
            'countries' => $countries,
            'filters' => [
                'name'      => $request->get('name', ''),
                'is_active' => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'sort'      => $request->get('sort', 'id'),
                'direction' => $request->get('direction', 'asc'),
                'per_page'  => $perPage,
            ],
            'regionOptions' => $this->regionOptions(),
            'localeOptions' => $this->localeOptions(),
        ]);
    }

    /**
     * Batch update. Valida unicidad con UniqueNormalizedName (misma rule
     * que el Form) — duplicados intra-batch Y contra DB. Si hay errores,
     * no toca nada. Persistencia en transacción para atomicidad.
     */
    public function editAllUpdate(EditAllUpdateRequest $request, CountryService $service)
    {
        $result = $service->editAllBatch($request->validated()['changes']);

        if (!empty($result['errors'])) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return redirect()
            ->route('system_management.countries.edit_all')
            ->with('success', __('global.updated_success') . " ({$result['touched']})");
    }

    // ── EXPORTS (queued jobs por formato) ───────────────────────────────────

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyección de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        $allowedColumns = ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active', 'created_at', 'updated_at', 'creator'];

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
            'title'                   => $data['title']                   ?? __('countries.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    public function exportPdf(Request $request, CountryService $service)
    {
        return $this->dispatchExport($request, $service, 'pdf', \App\Jobs\SystemManagement\Countries\GenerateCountriesPdfJob::class);
    }

    public function exportExcel(Request $request, CountryService $service)
    {
        return $this->dispatchExport($request, $service, 'excel', \App\Jobs\SystemManagement\Countries\GenerateCountriesExcelJob::class);
    }

    public function exportCsv(Request $request, CountryService $service)
    {
        return $this->dispatchExport($request, $service, 'csv', \App\Jobs\SystemManagement\Countries\GenerateCountriesCsvJob::class);
    }

    public function exportWord(Request $request, CountryService $service)
    {
        return $this->dispatchExport($request, $service, 'word', \App\Jobs\SystemManagement\Countries\GenerateCountriesWordJob::class);
    }

    /**
     * Helper común de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Reduce 5 líneas idénticas por endpoint a 1 método.
     */
    protected function dispatchExport(Request $request, CountryService $service, string $format, string $jobClass): \Illuminate\Http\RedirectResponse
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
    protected function assertExportLimit(CountryService $service, string $format, array $options): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limit = \App\Models\Setting::getExportLimit('countries', $format);
        if ($limit === 0) return;  // CSV streaming, sin límite

        $count = $service->countForExport($options);
        if ($count > $limit) {
            abort(422, __('countries.export_limit_exceeded', [
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
            new \App\Exports\SystemManagement\Countries\CountriesImportTemplate(),
            __('countries.import_template_filename')
        );
    }

    public function import(ImportRequest $request, CountryService $service)
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

