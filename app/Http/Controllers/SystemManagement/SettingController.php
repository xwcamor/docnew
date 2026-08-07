<?php

namespace App\Http\Controllers\SystemManagement;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Http\Requests\SystemManagement\Setting\StoreRequest;
use App\Http\Requests\SystemManagement\Setting\UpdateRequest;
use App\Http\Requests\SystemManagement\Setting\DeleteRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Requests\SystemManagement\Setting\BulkDeleteRequest;
use App\Http\Requests\SystemManagement\Setting\BulkSetActiveRequest;
use App\Http\Requests\SystemManagement\Setting\BulkRestoreRequest;
use App\Http\Requests\SystemManagement\Setting\ForceDeleteRequest;
use App\Http\Requests\SystemManagement\Setting\EditAllUpdateRequest;
use App\Http\Requests\SystemManagement\Setting\ImportRequest;
use App\Services\SystemManagement\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Permisos: todas las rutas viven dentro de `role:super` middleware en
 * routes/system_management.php. Por eso NO chequeamos permission:settings.*
 * por método — sería redundante. Al clonar a módulos per-tenant, mover las
 * rutas fuera de ese grupo y aplicar `permission:patients.edit` etc.
 *
 * Acciones críticas (force_delete, bulk_restore, restore, undo_last_delete)
 * tienen su propio abort_unless(super) como defense-in-depth.
 */
class SettingController extends Controller
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
        $settings = Setting::filter($request)
            ->select(
                'settings.id', 'settings.slug', 'settings.name',
                'settings.key', 'settings.type', 'settings.value', 'settings.group',
                'settings.is_secret', 'settings.is_active',
                'settings.created_at', 'settings.updated_at', 'settings.created_by',
            )
            ->with(['creator:id,name,email'])
            ->orderByFavoriteFirst($userId)
            ->paginate($perPage)
            ->withQueryString();

        // is_favorite viene como int 0/1 o bool según driver — normalizar.
        $settings->getCollection()->transform(function ($r) {
            $r->is_favorite = (bool) ($r->is_favorite ?? false);
            return $r;
        });

        $totalUnfiltered = Setting::count();

        // 'name' puede venir como string o array — normalizamos a array.
        $names = $request->get('name', []);
        if (is_string($names)) {
            $names = $names === '' ? [] : [$names];
        }

        return inertia('Settings/Index', [
            'settings' => array_merge($settings->toArray(), [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Límites de export por formato — el frontend deshabilita formatos
            // que exceden su límite. CSV con 0 = sin límite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('settings'),
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
            'filterSchema' => Setting::filterSchema(),
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
        return inertia('Settings/Form', [
            'setting' => null,
        ]);
    }

    public function store(StoreRequest $request, SettingService $service)
    {
        $service->create($request->validated());

        return redirect()
            ->route('system_management.settings.index')
            ->with('success', __('global.created_success'));
    }

    /**
     * Solo super ve settinges soft-deleted (con motivo + deleter).
     * Otros usuarios reciben 404 — privacidad: no exponemos que existió.
     */
    public function show(Request $request, $slug)
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = Setting::with(['creator:id,name,email', 'deleter:id,name,email']);
        if ($isSuper) {
            $query->withTrashed();
        }

        $setting = $query->where('slug', $slug)->firstOrFail();

        // Track recent view (best-effort, no rompe la pantalla si falla).
        if ($userId = $request->user()?->id) {
            try {
                \App\Models\UserRecentView::track($userId, Setting::class, $setting->id);
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
                    ->where('auditable_type', \App\Models\Setting::class)
                    ->where('auditable_id', $setting->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Settings/Show', [
            'setting' => [
                'id'                  => $setting->id,
                'slug'                => $setting->slug,
                'name'                => $setting->name,
                'key'                 => $setting->key,
                'type'                => $setting->type,
                'value'               => $setting->value,
                'group'               => $setting->group,
                'description'         => $setting->description,
                'is_secret'           => $setting->is_secret,
                'is_active'           => $setting->is_active,
                'created_at'          => $setting->created_at,
                'updated_at'          => $setting->updated_at,
                'deleted_at'          => $setting->deleted_at,
                'deleted_description' => $setting->deleted_description,
                'creator' => $setting->creator ? [
                    'id'    => $setting->creator->id,
                    'name'  => $setting->creator->name,
                    'email' => $setting->creator->email,
                ] : null,
                'deleter' => $setting->deleter ? [
                    'id'    => $setting->deleter->id,
                    'name'  => $setting->deleter->name,
                    'email' => $setting->deleter->email,
                ] : null,
            ],
            'activity' => $activity,
            'recordAudit' => $this->recordAuditMeta($setting),
        ]);
    }

    public function edit(Setting $setting)
    {
        return inertia('Settings/Form', [
            'setting' => [
                'id'          => $setting->id,
                'slug'        => $setting->slug,
                'key'         => $setting->key,
                'name'        => $setting->name,
                'type'        => $setting->type,
                'value'       => $setting->value,
                'group'       => $setting->group,
                'description' => $setting->description,
                'is_secret'   => (bool) $setting->is_secret,
                'is_active'   => (bool) $setting->is_active,
            ],
        ]);
    }

    /**
     * Sube el logo de la app: guarda el archivo en storage/app/public/branding/
     * y actualiza el setting `app.logo_url` con el path /storage/branding/...
     *
     * Borra el logo anterior si existe para no acumular basura.
     * Solo super (gate por route group).
     */
    public function uploadAppLogo(Request $request)
    {
        $maxKb = Setting::getInt('uploads.tenant_logo_max_mb', 2) * 1024;
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,svg,webp', "max:{$maxKb}"],
        ], [
            'logo.required' => __('settings.logo_required'),
            'logo.image'    => __('settings.logo_image'),
            'logo.mimes'    => __('settings.logo_mimes'),
            'logo.max'      => __('settings.logo_max', ['mb' => $maxKb / 1024]),
        ]);

        $setting = Setting::where('key', 'app.logo_url')->firstOrFail();

        $this->deleteAppLogoFile($setting->value);

        $ext      = $request->file('logo')->getClientOriginalExtension();
        $filename = 'logo_' . time() . '_' . Str::random(8) . '.' . $ext;
        $path     = $request->file('logo')->storeAs('branding', $filename, 'public');

        $setting->value = '/storage/' . $path;
        $setting->save();

        return back()->with('success', __('settings.logo_uploaded'));
    }

    /**
     * Quita el logo actual: borra el archivo físico (si está bajo /storage/)
     * y limpia el setting `app.logo_url`.
     */
    public function removeAppLogo()
    {
        $setting = Setting::where('key', 'app.logo_url')->firstOrFail();

        $this->deleteAppLogoFile($setting->value);

        $setting->value = '';
        $setting->save();

        return back()->with('success', __('settings.logo_removed'));
    }

    /**
     * Borra el archivo físico si el value apunta a /storage/branding/...
     * Si el value era una URL externa (http) no toca nada.
     */
    private function deleteAppLogoFile(?string $value): void
    {
        if (!$value || !str_starts_with($value, '/storage/branding/')) return;

        $relative = ltrim(substr($value, strlen('/storage/')), '/');
        if (Storage::disk('public')->exists($relative)) {
            Storage::disk('public')->delete($relative);
        }
    }

    public function update(UpdateRequest $request, Setting $setting, SettingService $service)
    {
        $service->update($setting, $request->validated());

        return redirect()
            ->route('system_management.settings.index')
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

        $settings = Setting::onlyTrashed()
            ->with(['deleter:id,name,email'])
            ->select('settings.id', 'settings.slug', 'settings.name', 'settings.is_active', 'settings.deleted_at', 'settings.deleted_by', 'settings.deleted_description', 'settings.created_at')
            ->filter($request)
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Settings/Trash', [
            'settings' => $settings,
            'filters' => [
                'name'     => $request->get('name', ''),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, string $slug, SettingService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $setting = Setting::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($setting);

        return redirect()
            ->route('system_management.settings.trash')
            ->with('success', __('global.restored_success'));
    }

    public function bulkRestore(BulkRestoreRequest $request, SettingService $service)
    {
        $result = $service->bulkRestore($request->validated()['ids']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.settings.trash')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.settings.trash')
            ->with('success', __('global.restored_success') . " ({$result['restored']})");
    }

    /**
     * Hard-delete con triple guard: super + onlyTrashed + nombre exacto.
     * Audit log se escribe ANTES del delete físico (sobrevive al borrado).
     */
    public function forceDelete(ForceDeleteRequest $request, string $slug, SettingService $service)
    {
        $setting = Setting::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $data   = $request->validated();

        if (trim($data['name_confirmation']) !== $setting->name) {
            return back()->withErrors([
                'name_confirmation' => __('global.force_delete_name_mismatch'),
            ]);
        }

        $service->forceDelete($setting, $data['reason']);

        return redirect()
            ->route('system_management.settings.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ── SOFT-DELETE + UNDO + DUPLICATE ─────────────────────────────────────

    public function delete(Setting $setting)
    {
        return inertia('Settings/Delete', [
            'setting' => [
                'id'        => $setting->id,
                'slug'      => $setting->slug,
                'name'      => $setting->name,
                'is_active' => $setting->is_active,
            ],
            'dependents' => $setting->countDependents(),
        ]);
    }

    public function deleteSave(DeleteRequest $request, Setting $setting, SettingService $service)
    {
        // Para Setting/countries block=false → no bloquea aquí. El patrón sirve
        // para módulos donde sí cuente (ej. doctors con appointments futuros).
        if ($setting->hasBlockingDependents()) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $service->delete($setting, $request->deleted_description);

        // Claim de undo en sesión (60s). La lógica de undo valida sesión,
        // no rol — quien eliminó puede deshacer aunque no sea super.
        $this->storeUndoableDelete([$setting->id]);

        return redirect()
            ->route('system_management.settings.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$setting->id]));
    }

    /**
     * Clona la región. El service maneja la generación del sufijo "(copia)"
     * con sanity guard. Si devuelve null → 100 intentos agotados (raro).
     */
    public function duplicate(Request $request, Setting $setting, SettingService $service)
    {
        $clone = $service->duplicate($setting);

        if (!$clone) {
            return redirect()
                ->route('system_management.settings.index')
                ->with('error', __('global.duplicate_failed'));
        }

        return redirect()
            ->route('system_management.settings.index')
            ->with('success', __('global.duplicated_success'));
    }

    // ── BULK OPERATIONS ─────────────────────────────────────────────────────

    public function bulkDelete(BulkDeleteRequest $request, SettingService $service)
    {
        $data = $request->validated();

        // Service maneja threshold + dependency check + delete loop. No
        // ofrecemos undo en async: el delete real ocurre después del
        // redirect y el window de 60s no calza con un job que tarda minutos.
        $result = $service->bulkDelete($data['ids'], $data['deleted_description']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.settings.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        if (!empty($result['blocked'])) {
            return back()->with('error', __('global.cannot_delete_has_dependents'));
        }

        $this->storeUndoableDelete($result['deleted_ids']);

        return redirect()
            ->route('system_management.settings.index')
            ->with('success', __('global.deleted_success') . " ({$result['count']})")
            ->with('recentDelete', $this->buildRecentDeletePayload($result['deleted_ids']));
    }

    /**
     * Undo dentro del window de 60s. Validamos contra session claim (no rol):
     * quien eliminó puede deshacer su propio error sin ser super.
     * Defense in depth: además exigimos `deleted_by = current_user`.
     */
    public function undoLastDelete(Request $request, SettingService $service)
    {
        $claim = session('settings.recent_delete');
        if (!$claim || !is_array($claim) || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('settings.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $userId = auth()->id();
        $settings = Setting::onlyTrashed()
            ->whereIn('id', $claim['ids'])
            ->where('deleted_by', $userId)
            ->get();

        if ($settings->isEmpty()) {
            session()->forget('settings.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        foreach ($settings as $setting) {
            $service->restore($setting);
        }
        session()->forget('settings.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    /** Persiste el claim en sesión por el window configurado para validar el undo. */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['settings.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds((int) config('settings.undo_window_seconds', 60)),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return [
            'count'   => count($ids),
            'seconds' => (int) config('settings.undo_window_seconds', 60),
        ];
    }

    public function bulkSetActive(BulkSetActiveRequest $request, SettingService $service)
    {
        $data   = $request->validated();
        $result = $service->bulkSetActive($data['ids'], (bool) $data['is_active']);

        if ($result['queued']) {
            return redirect()
                ->route('system_management.settings.index')
                ->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return redirect()
            ->route('system_management.settings.index')
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

        $settings = Setting::filter($request)
            ->select('settings.id', 'settings.slug', 'settings.name', 'settings.is_active')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Settings/EditAll', [
            'settings' => $settings,
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
    public function editAllUpdate(EditAllUpdateRequest $request, SettingService $service)
    {
        $result = $service->editAllBatch($request->validated()['changes']);

        if (!empty($result['errors'])) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return redirect()
            ->route('system_management.settings.edit_all')
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
            'title'                   => $data['title']                   ?? __('settings.export_title'),
            'include_filters_summary' => $data['include_filters_summary'] ?? true,
            'filters'                 => $data['filters']                 ?? [],
            'orientation'             => $data['orientation']             ?? 'portrait',
            'paper_size'              => $data['paper_size']              ?? 'a4',
            'autofilter'              => $data['autofilter']              ?? true,
            'freeze_header'           => $data['freeze_header']           ?? true,
        ];
    }

    public function exportPdf(Request $request, SettingService $service)
    {
        return $this->dispatchExport($request, $service, 'pdf', \App\Jobs\SystemManagement\Settings\GenerateSettingsPdfJob::class);
    }

    public function exportExcel(Request $request, SettingService $service)
    {
        return $this->dispatchExport($request, $service, 'excel', \App\Jobs\SystemManagement\Settings\GenerateSettingsExcelJob::class);
    }

    public function exportCsv(Request $request, SettingService $service)
    {
        return $this->dispatchExport($request, $service, 'csv', \App\Jobs\SystemManagement\Settings\GenerateSettingsCsvJob::class);
    }

    public function exportWord(Request $request, SettingService $service)
    {
        return $this->dispatchExport($request, $service, 'word', \App\Jobs\SystemManagement\Settings\GenerateSettingsWordJob::class);
    }

    /**
     * Helper común de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Reduce 5 líneas idénticas por endpoint a 1 método.
     */
    protected function dispatchExport(Request $request, SettingService $service, string $format, string $jobClass): \Illuminate\Http\RedirectResponse
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
    protected function assertExportLimit(SettingService $service, string $format, array $options): void
    {
        if (\App\Support\FeatureGate::allows('export_unlimited_rows', auth()->user())
            && config('features.features.export_unlimited_rows') !== null) {
            return;
        }

        $limit = \App\Models\Setting::getExportLimit('settings', $format);
        if ($limit === 0) return;  // CSV streaming, sin límite

        $count = $service->countForExport($options);
        if ($count > $limit) {
            abort(422, __('settings.export_limit_exceeded', [
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
            new \App\Exports\SystemManagement\Settings\SettingsImportTemplate(),
            __('settings.import_template_filename')
        );
    }

    public function import(ImportRequest $request, SettingService $service)
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

