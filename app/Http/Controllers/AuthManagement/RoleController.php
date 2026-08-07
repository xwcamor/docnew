<?php

namespace App\Http\Controllers\AuthManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthManagement\Role\BulkDeleteRoleRequest;
use App\Http\Requests\AuthManagement\Role\BulkRestoreRoleRequest;
use App\Http\Requests\AuthManagement\Role\BulkSetActiveRoleRequest;
use App\Http\Requests\AuthManagement\Role\DeleteRoleRequest;
use App\Http\Requests\AuthManagement\Role\EditAllUpdateRoleRequest;
use App\Http\Requests\AuthManagement\Role\ForceDeleteRoleRequest;
use App\Http\Requests\AuthManagement\Role\ImportRoleRequest;
use App\Http\Requests\AuthManagement\Role\StoreRoleRequest;
use App\Http\Requests\AuthManagement\Role\UpdateRoleRequest;
use App\Http\Resources\AuditLogResource;
use App\Jobs\AuthManagement\Roles\GenerateRolesCsvJob;
use App\Jobs\AuthManagement\Roles\GenerateRolesExcelJob;
use App\Jobs\AuthManagement\Roles\GenerateRolesPdfJob;
use App\Jobs\AuthManagement\Roles\GenerateRolesWordJob;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\AuthManagement\RoleService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles (Perfiles) — gestión de roles Spatie con scope multi-tenant.
 *
 * super    ve TODOS los roles (incluidos los globales del sistema)
 * admin          ve solo los roles de SU tenant
 * user           no debería llegar aquí (rutas con role:super|admin)
 *
 * Los CORE permissions (system_management.*) NO se exponen al admin para
 * asignar — son super only. La lista filtrada se calcula en
 * `assignablePermissions()`.
 */
class RoleController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    /**
     * Roles del sistema — protegidos contra edit/delete vía UI.
     * Solo el super puede tocarlos directo via tinker o DB.
     *
     * `user` se removió del sistema: los workers sin perfil custom asignado
     * quedan sin rol (= sin permisos). admin crea sus propios perfiles para
     * delegarles permisos cuando arma el equipo.
     */
    protected array $systemRoleNames = ['super', 'admin', 'api'];

    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: []; }
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, fn ($c) => is_array($c) && !empty($c['field']) && !empty($c['op'])));
    }

    public function index(Request $request)
    {
        $user         = $request->user();
        $isSuper = $user->hasRole('super');

        $advanced = $this->parseAdvancedWhere($request);

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $query = Role::query()
            ->select('roles.*')
            ->where('guard_name', 'web')
            ->orderByFavoriteFirst($user->id);

        // Multi-tenant scoping: el listado de Roles es para GESTIONAR perfiles
        // custom del workspace. Los roles globales del sistema (super,
        // admin, api) NO se muestran aquí porque son protegidos — el admin no
        // los puede editar, borrar ni clonar. Si los mostraramos, serian
        // ruido + frustracion al intentar tocarlos.
        //
        // El dropdown de "asignar rol al crear user" (UserController) si
        // incluye `admin` global aparte, porque ahi joe necesita poder
        // nombrar otro admin para su workspace.
        if (!$isSuper) {
            // El admin ve los roles de SU tenant + los GLOBALES CUSTOM (tenant_id
            // null que NO son de sistema). Los globales custom los crea el super
            // como plantilla para todos los workspaces: el admin los ve y los
            // asigna, pero NO los edita (solo-lectura → is_editable=false abajo).
            // Los roles de sistema (super/admin/api) siguen ocultos.
            $query->where(function ($q) use ($user) {
                $q->where('tenant_id', $user->tenant_id)
                  ->orWhere(fn ($qq) => $qq->whereNull('tenant_id')
                      ->whereNotIn('name', $this->systemRoleNames));
            });
        }

        // Filtros — name (multi-tag con accent-insensitive), is_active, scope, date.
        $isPgsql = config('database.default') === 'pgsql';
        $query->when($request->filled('name'), function ($q) use ($request, $isPgsql) {
            $names = is_array($request->name) ? $request->name : [$request->name];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (!empty($names)) {
                $q->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        });
        $query->when($request->filled('is_active'), function ($q) use ($request) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });
        $query->when($request->filled('scope'), function ($q) use ($request) {
            $scope = is_array($request->scope) ? $request->scope : [$request->scope];
            $q->where(function ($qq) use ($scope) {
                if (in_array('system', $scope, true)) $qq->orWhereNull('tenant_id');
                if (in_array('tenant', $scope, true)) $qq->orWhereNotNull('tenant_id');
            });
        });
        $query->when($request->filled('created_from'), fn ($q) => $q->where('roles.created_at', '>=', $request->created_from . ' 00:00:00'));
        $query->when($request->filled('created_to'),   fn ($q) => $q->where('roles.created_at', '<=', $request->created_to . ' 23:59:59'));

        $query->when(!empty($advanced), fn ($q) => \App\Services\Automations\Support\FilterApplier::apply($q, ['where' => $advanced], \App\Models\Role::filterSchema()));

        $query->when($request->filled('only_favorites') && filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN), function ($q) use ($user) {
            $q->whereExists(function ($sub) use ($user) {
                $sub->select(\DB::raw(1))
                    ->from('user_favorites')
                    ->whereColumn('user_favorites.favoritable_id', 'roles.id')
                    ->where('user_favorites.favoritable_type', Role::class)
                    ->where('user_favorites.user_id', $user->id);
            });
        });

        if (in_array($request->sort, ['id', 'name', 'description', 'is_active', 'created_at']) && in_array($request->direction, ['asc', 'desc'])) {
            $query->orderBy('roles.' . $request->sort, $request->direction);
        } elseif (in_array($request->sort, ['permissions_count', 'users_count'], true) && in_array($request->direction, ['asc', 'desc'])) {
            // Orden por los conteos del withCount (alias en el SELECT final).
            $query->orderBy($request->sort, $request->direction);
        } elseif ($request->sort === 'tenant_name' && in_array($request->direction, ['asc', 'desc'])) {
            // Orden por workspace: el nombre vive en tenants. left join (roles
            // globales tienen tenant_id NULL). select('roles.*') ya evita colisión.
            $query->leftJoin('tenants', 'tenants.id', '=', 'roles.tenant_id')
                  ->orderBy('tenants.name', $request->direction);
        }

        $roles = $query
            ->withCount('permissions', 'users')
            ->paginate($perPage)
            ->withQueryString();

        // Eager-map tenant_id → tenant_name para no exponer el ID interno del tenant.
        $tenantsMap = Tenant::query()->pluck('name', 'id');

        $rolesArray = $roles->toArray();
        foreach ($rolesArray['data'] as &$row) {
            // slug se mantiene en el payload SIEMPRE — la URL rutea por slug
            // (getRouteKeyName). NO se muestra como columna salvo a super
            // (eso lo gatea el frontend), pero el dato debe viajar para routear.
            $row['tenant_name'] = $row['tenant_id'] ? ($tenantsMap[$row['tenant_id']] ?? null) : null;
            $row['is_system']   = in_array($row['name'], $this->systemRoleNames, true) && $row['tenant_id'] === null;
            // Editable: super edita todo salvo los de sistema; el admin solo los
            // de SU tenant (los globales custom que ve son solo-lectura).
            $row['is_editable'] = $isSuper
                ? !$row['is_system']
                : ((int) ($row['tenant_id'] ?? 0) === (int) ($user->tenant_id ?? 0));
        }
        unset($row);

        $totalUnfiltered = Role::query()->where('guard_name', 'web')
            ->when(!$isSuper, fn ($q) => $q->where(function ($qq) use ($user) {
                $qq->where('tenant_id', $user->tenant_id)
                   ->orWhere(fn ($q2) => $q2->whereNull('tenant_id')->whereNotIn('name', $this->systemRoleNames));
            }))
            ->count();

        $names = $request->get('name', []);
        if (is_string($names)) $names = $names === '' ? [] : [$names];

        return inertia('Roles/Index', [
            'roles' => array_merge($rolesArray, ['total_unfiltered' => $totalUnfiltered]),
            'filters' => [
                'name'         => array_values($names),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'scope'        => $request->get('scope', []),
                'created_from'   => $request->get('created_from', ''),
                'created_to'     => $request->get('created_to', ''),
                'only_favorites' => $request->boolean('only_favorites'),
                'advanced_where' => $advanced,
                'sort'           => $request->get('sort', 'id'),
                'direction'      => $request->get('direction', 'desc'),
                'per_page'       => $perPage,
            ],
            'isSuper'      => $isSuper,
            'filterSchema' => \App\Models\Role::filterSchema(),
            'exportLimits' => \App\Models\Setting::getExportLimits('roles'),
        ]);
    }

    // ─── DELETE con motivo (UX igual que Regions) ─────────────────────────
    public function delete(Request $request, Role $role)
    {
        $this->authorizeAccess($request->user(), $role);
        abort_if($this->isSystemRole($role), 403, 'No puedes eliminar roles del sistema.');

        return inertia('Roles/Delete', [
            'role' => [
                'id'                => $role->id,
                // slug viaja SIEMPRE — el form de confirmación rutea por slug
                // (roles.deleteSave). No se muestra; sin él el submit se rompe.
                'slug'              => $role->slug,
                'name'              => $role->name,
                'description'       => $role->description,
                'users_count'       => $role->users()->count(),
                'permissions_count' => $role->permissions()->count(),
            ],
        ]);
    }

    public function deleteSave(DeleteRoleRequest $request, Role $role, RoleService $service)
    {
        $this->authorizeAccess($request->user(), $role);
        abort_if($this->isSystemRole($role), 403, 'No puedes eliminar roles del sistema.');

        $service->delete($role, $request->validated()['deleted_description']);

        $this->storeUndoableDelete([$role->id]);

        return redirect()->route('user_management.roles.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$role->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['roles.recent_delete' => [
            'ids'        => array_values($ids),
            'expires_at' => now()->addSeconds(60)->toIso8601String(),
        ]]);
    }

    /** Payload que va al frontend via flash para disparar el toast de undo. */
    protected function buildRecentDeletePayload(array $ids): array
    {
        return ['count' => count($ids), 'seconds' => 60];
    }

    /**
     * Undo dentro del window de 60s. UNDO != papelera: es la red de seguridad
     * inmediata para quien borro. Lo puede hacer el admin (no requiere
     * super). Defense in depth: ademas del claim validamos
     * deleted_by = current user.
     */
    public function undoLastDelete(Request $request, RoleService $service)
    {
        $claim = session('roles.recent_delete');
        if (!$claim || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('roles.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $userId = $request->user()?->id;
        $roles = Role::onlyTrashed()
            ->whereIn('id', $claim['ids'])
            ->where('deleted_by', $userId)
            ->get();

        if ($roles->isEmpty()) {
            session()->forget('roles.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        foreach ($roles as $role) {
            $service->restore($role);
        }
        session()->forget('roles.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    // ─── TRASH + Restore + Force-delete ────────────────────────────────────
    public function trash(Request $request)
    {
        $user         = $request->user();
        $isSuper = $user->hasRole('super');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        $query = Role::onlyTrashed()->where('guard_name', 'web')
            ->with(['deleter:id,name,email']);

        if (!$isSuper) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $query->when($request->filled('name'), function ($q) use ($request) {
            $name = $request->get('name');
            $isPgsql = config('database.default') === 'pgsql';
            if ($isPgsql) {
                $q->whereRaw('unaccent(lower(name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
            } else {
                $q->where('name', 'like', '%' . $name . '%');
            }
        });

        $roles = $query->orderByDesc('deleted_at')->paginate($perPage)->withQueryString();

        $tenantsMap = Tenant::query()->pluck('name', 'id');
        $rolesArray = $roles->toArray();
        foreach ($rolesArray['data'] as &$row) {
            // slug se mantiene siempre — restore/force_delete rutean por slug.
            // El frontend lo muestra como dato solo a super.
            $row['tenant_name'] = $row['tenant_id'] ? ($tenantsMap[$row['tenant_id']] ?? null) : null;
        }
        unset($row);

        return inertia('Roles/Trash', [
            'roles' => $rolesArray,
            'filters' => [
                'name'     => $request->get('name', ''),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, $slug, RoleService $service)
    {
        $role = Role::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $this->authorizeAccess($request->user(), $role);

        $service->restore($role);

        return redirect()->route('user_management.roles.trash')
            ->with('success', __('global.restored_success', ['default' => 'Perfil restaurado.']));
    }

    public function forceDelete(ForceDeleteRoleRequest $request, $slug, RoleService $service)
    {
        $role = Role::onlyTrashed()->where('slug', $slug)->firstOrFail();

        // Patrón unificado con Regions/Plans: el campo se llama `name_confirmation`
        // (no `confirm_name`) — el modal compartido ForceDeleteModal envía esa key.
        $data = $request->validated();

        abort_unless($data['name_confirmation'] === $role->name, 422, 'El nombre de confirmación no coincide.');

        $service->forceDelete($role, $data['reason']);

        return redirect()->route('user_management.roles.trash')
            ->with('success', __('global.permanently_deleted_success', ['default' => 'Perfil eliminado permanentemente.']));
    }

    // ─── Duplicate ─────────────────────────────────────────────────────────
    public function duplicate(Request $request, Role $role, RoleService $service)
    {
        $this->authorizeAccess($request->user(), $role);
        $copy = $service->duplicate($role);

        if (!$copy) {
            return back()->with('error', __('global.duplicate_failed', ['default' => 'No se pudo duplicar el perfil.']));
        }

        return redirect()->route('user_management.roles.edit', $copy->id)
            ->with('success', __('global.duplicated_success', ['default' => 'Perfil duplicado.']));
    }

    // ─── Bulk ops ──────────────────────────────────────────────────────────
    public function bulkDelete(BulkDeleteRoleRequest $request, RoleService $service)
    {
        $data = $request->validated();

        // Filtrar por tenant scope si no es super.
        $user = $request->user();
        $allowedIds = Role::whereIn('id', $data['ids'])
            ->when(!$user->hasRole('super'), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->whereNotIn('name', $this->systemRoleNames)
            ->pluck('id')->all();

        $result = $service->bulkDelete($allowedIds, $data['deleted_description']);

        if (!empty($allowedIds)) {
            $this->storeUndoableDelete($allowedIds);
        }

        return back()
            ->with('success', __('global.bulk_deleted', [
                'count' => count($result['deleted']),
            ], 'Eliminados: :count.'))
            ->with('recentDelete', $this->buildRecentDeletePayload($allowedIds));
    }

    public function bulkSetActive(BulkSetActiveRoleRequest $request, RoleService $service)
    {
        $data = $request->validated();

        $user = $request->user();
        $allowedIds = Role::whereIn('id', $data['ids'])
            ->when(!$user->hasRole('super'), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->whereNotIn('name', $this->systemRoleNames)
            ->pluck('id')->all();

        $changed = $service->bulkSetActive($allowedIds, (bool) $data['is_active']);

        return back()->with('success', __('global.bulk_updated', [
            'count' => $changed,
        ]));
    }

    public function bulkRestore(BulkRestoreRoleRequest $request, RoleService $service)
    {
        $restored = $service->bulkRestore($request->validated()['ids']);

        return back()->with('success', __('global.bulk_restored', [
            'count' => $restored,
        ], 'Restaurados: :count.'));
    }

    public function show(Request $request, Role $role)
    {
        // Ver es solo-lectura: el admin puede ABRIR los roles globales custom
        // (plantillas del super) además de los suyos. Editar/borrar siguen
        // restringidos por authorizeAccess (estricto).
        $this->authorizeView($request->user(), $role);

        $isSuper = $request->user()->hasRole('super');
        $tenantName   = $role->tenant_id
            ? Tenant::where('id', $role->tenant_id)->value('name')
            : null;

        // Permissions asignados — agrupados por módulo para la UI.
        $permissions = $role->permissions()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($p) {
                [$module, $action] = explode('.', $p->name, 2) + [null, null];
                return ['id' => $p->id, 'name' => $p->name, 'module' => $module, 'action' => $action];
            });

        $usersCount = $role->users()->count();

        $role->loadMissing(['creator:id,name,email', 'deleter:id,name,email']);

        // Historial de auditoria — solo super/admin lo ven (igual que Regions).
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? AuditLogResource::collection(
                AuditLog::query()
                    ->where('auditable_type', Role::class)
                    ->where('auditable_id', $role->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Roles/Show', [
            'role' => [
                'id'                  => $role->id,
                // slug viaja siempre — la URL/rutas usan slug (getRouteKeyName).
                // El frontend lo muestra como dato SOLO a super.
                'slug'                => $role->slug,
                'name'                => $role->name,
                'description'         => $role->description,
                'tenant_id'           => $role->tenant_id,
                'tenant_name'         => $tenantName,
                'is_system'           => $this->isSystemRole($role),
                'is_active'           => $role->is_active,
                'permissions_count'   => $permissions->count(),
                'users_count'         => $usersCount,
                'created_at'          => $role->created_at,
                'updated_at'          => $role->updated_at,
                'deleted_at'          => $role->deleted_at,
                'deleted_description' => $role->deleted_description,
                'creator'             => $role->creator ? ['id' => $role->creator->id, 'name' => $role->creator->name, 'email' => $role->creator->email] : null,
                'deleter'             => $role->deleter ? ['id' => $role->deleter->id, 'name' => $role->deleter->name, 'email' => $role->deleter->email] : null,
            ],
            'permissions'  => $permissions,
            'activity'     => $activity,
            'recordAudit'  => $this->recordAuditMeta($role),
            'isSuper'      => $isSuper,
        ]);
    }

    public function create(Request $request)
    {
        return inertia('Roles/Form', [
            'role'                  => null,
            'assignablePermissions' => $this->assignablePermissions($request->user()),
            'tenantOptions'         => $this->tenantOptionsFor($request->user()),
        ]);
    }

    public function store(StoreRoleRequest $request)
    {
        $user         = $request->user();
        $isSuper = $user->hasRole('super');

        // tenant efectivo: super elige (nullable = global), admin siempre el suyo
        $tenantId = $isSuper
            ? ($request->filled('tenant_id') ? (int) $request->input('tenant_id') : null)
            : $user->tenant_id;

        $data = $request->validated();

        $role = Role::create([
            'name'        => $data['name'],
            'description' => $data['description'],
            'guard_name'  => 'web',
            'tenant_id'   => $tenantId,
        ]);

        // Sync solo permisos asignables (filtra core si es admin).
        $assignableIds = $this->assignablePermissions($user)->pluck('id')->all();
        $toSync        = array_intersect($data['permissions'] ?? [], $assignableIds);
        $role->syncPermissions(Permission::whereIn('id', $toSync)->get());

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('user_management.roles.index')
            ->with('success', __('global.created_success'));
    }

    public function edit(Request $request, Role $role)
    {
        $this->authorizeAccess($request->user(), $role);
        abort_if($this->isSystemRole($role), 403, 'No puedes editar roles del sistema.');

        return inertia('Roles/Form', [
            'role' => [
                'id'              => $role->id,
                // slug viaja siempre — el form envía el update a roles/{role}
                // y la ruta liga por slug (getRouteKeyName). Sin esto la URL
                // del PUT queda sin parámetro y el guardado nunca llega.
                'slug'            => $role->slug,
                'name'            => $role->name,
                'description'     => $role->description,
                'tenant_id'       => $role->tenant_id,
                'permission_ids'  => $role->permissions->pluck('id')->all(),
            ],
            'assignablePermissions' => $this->assignablePermissions($request->user()),
            'tenantOptions'         => $this->tenantOptionsFor($request->user()),
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $this->authorizeAccess($request->user(), $role);
        abort_if($this->isSystemRole($role), 403, 'No puedes editar roles del sistema.');

        $user = $request->user();
        $data = $request->validated();

        $role->update(['name' => $data['name'], 'description' => $data['description']]);

        $assignableIds = $this->assignablePermissions($user)->pluck('id')->all();
        $toSync        = array_intersect($data['permissions'] ?? [], $assignableIds);
        $role->syncPermissions(Permission::whereIn('id', $toSync)->get());

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('user_management.roles.index')
            ->with('success', __('global.updated_success'));
    }

    public function destroy(Request $request, Role $role)
    {
        $this->authorizeAccess($request->user(), $role);
        abort_if($this->isSystemRole($role), 403, 'No puedes eliminar roles del sistema.');
        abort_if($role->users()->count() > 0, 409, 'No puedes eliminar un rol que tiene usuarios asignados.');

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('user_management.roles.index')
            ->with('success', __('global.deleted_success'));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function authorizeAccess($user, Role $role): void
    {
        $isSuper = $user->hasRole('super');
        if ($isSuper) return;
        // admin solo edita roles de su tenant.
        abort_unless($role->tenant_id === $user->tenant_id, 403);
    }

    /**
     * Acceso de SOLO-LECTURA (show). Más permisivo que authorizeAccess: el admin
     * puede ver los roles globales CUSTOM (tenant_id null que no son de sistema),
     * porque son plantillas que el super publica para todos los workspaces.
     */
    protected function authorizeView($user, Role $role): void
    {
        if ($user->hasRole('super')) return;
        $isGlobalCustom = $role->tenant_id === null && !$this->isSystemRole($role);
        abort_unless($role->tenant_id === $user->tenant_id || $isGlobalCustom, 403);
    }

    protected function isSystemRole(Role $role): bool
    {
        return in_array($role->name, $this->systemRoleNames, true) && $role->tenant_id === null;
    }

    /**
     * Permisos asignables al rol. Devuelve TODOS los permisos del sistema —
     * el SystemModulesSeeder ya filtra qué módulos producen permissions
     * (los core están excluidos a propósito).
     *
     * Grouped by module en la UI para mostrar Collapse panels.
     */
    /**
     * Módulos que NO se ofrecen como permisos en el form de perfiles. Dos motivos:
     *
     *  1. Catálogos GLOBALES super-only (oil_types/transformer_types/
     *     tap_changer_types): sus rutas están gateadas por `role:super`, no por
     *     `permission:*`, así que asignarlos a un perfil no haría nada útil.
     *  2. GESTIÓN DE ACCESOS (users/roles): el SUPER y el ADMIN ya tienen acceso
     *     a esos módulos por su rol, así que no se delegan vía un perfil custom.
     *     (Roles ya está gateado por role:super|admin en el sidebar; users se
     *     excluye aquí para que tampoco aparezca como permiso asignable.)
     *
     * NOTA (futuro): si alguno se libera para gestión por tenant, basta sacarlo
     * de esta lista y reaparece solo en el form.
     */
    protected array $superOnlyPermissionModules = ['oil_types', 'transformer_types', 'tap_changer_types', 'users', 'roles'];

    protected function assignablePermissions($user)
    {
        return Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($p) {
                [$module, $action] = explode('.', $p->name, 2) + [null, null];
                return [
                    'id'     => $p->id,
                    'name'   => $p->name,
                    'module' => $module,
                    'action' => $action,
                ];
            })
            ->reject(fn ($p) => in_array($p['module'], $this->superOnlyPermissionModules, true))
            ->values();
    }

    protected function tenantOptionsFor($user): array
    {
        if (!$user->hasRole('super')) return [];
        return Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($t) => ['value' => $t->id, 'label' => $t->name])
            ->all();
    }

    // ── EXPORTS ─────────────────────────────────────────────────────────────
    // Los 4 formatos van a queue como jobs async (mismo patron que Customers/Regions).
    // El job se encarga de la query con scope + render + Download record.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateRolesCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateRolesExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateRolesPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateRolesWordJob::class);
    }

    /**
     * Helper comun de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Mismo patron que Customers.
     */
    protected function dispatchExport(Request $request, string $format, string $jobClass)
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

        $limit = \App\Models\Setting::getExportLimit('roles', $format);
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

    /**
     * Cuenta filas a exportar segun scope+filters. Replica el tenant scoping
     * + filters del Job (que a su vez replica el index controller).
     */
    protected function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        $base = Role::query()->where('guard_name', 'web');

        // Tenant scoping idem buildQuery del Job.
        $user = auth()->user();
        if (!$user->hasRole('super')) {
            $tenantId        = $user->tenant_id;
            $systemRoleNames = ['super', 'admin', 'api'];
            $base->where(function ($q) use ($tenantId, $systemRoleNames) {
                $q->where('tenant_id', $tenantId)
                  ->orWhere(function ($qq) use ($systemRoleNames) {
                      $qq->whereNull('tenant_id')->whereIn('name', $systemRoleNames);
                  });
            });
        }

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return $base->count();
        }

        // Aplicar filters al base (replica BaseRoleExportJob::applyFilters).
        $f = $options['filters'] ?? [];
        $isPgsql = config('database.default') === 'pgsql';

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $names = array_filter($names, fn ($n) => $n !== '' && $n !== null);
            if (!empty($names)) {
                $base->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $base->where('is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($f['scope'])) {
            $scopeArr = is_array($f['scope']) ? $f['scope'] : [$f['scope']];
            $base->where(function ($qq) use ($scopeArr) {
                if (in_array('system', $scopeArr, true)) $qq->orWhereNull('tenant_id');
                if (in_array('tenant', $scopeArr, true)) $qq->orWhereNotNull('tenant_id');
            });
        }
        if (!empty($f['created_from'])) $base->where('roles.created_at', '>=', $f['created_from'] . ' 00:00:00');
        if (!empty($f['created_to']))   $base->where('roles.created_at', '<=', $f['created_to']   . ' 23:59:59');

        return $base->count();
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AuthManagement\Roles\RolesImportTemplate(),
            'plantilla-roles.xlsx'
        );
    }

    public function import(ImportRoleRequest $request)
    {
        $data    = $request->validated();
        $mode    = $data['mode'] ?? 'update_or_create';
        $dryRun  = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $importer = new \App\Imports\AuthManagement\Roles\RolesImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('RolesImport failed', [
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

    // ── Export helpers ──────────────────────────────────────────────────

    /**
     * Opciones normalizadas que reciben todos los jobs de export. Allowlist
     * de columnas previene inyeccion de campos sensibles.
     */
    protected function buildExportOptions(Request $request, string $format): array
    {
        // Sin 'id' (no se exporta). La columna `tenant` (workspace) SOLO es
        // exportable por super: el resto ve únicamente perfiles de su propio
        // tenant. Gate de seguridad real (no basta ocultarla en el front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'name', 'description', 'is_active', 'permissions_count', 'users_count',
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
            'title'                   => $data['title']                   ?? __('roles.export_title'),
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
            'auditable_type' => Role::class,
            'auditable_id'   => null,
            'module'         => 'roles',
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
            'url'        => route('user_management.roles.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    // ── EDIT ALL ────────────────────────────────────────────────────────────
    // Batch edit de name + description + is_active. Roles del sistema se
    // excluyen del listado (no editables). Transaccional.

    public function editAll(Request $request)
    {
        $user         = $request->user();
        $isSuper = $user->hasRole('super');

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $query = Role::query()
            ->where('guard_name', 'web')
            ->when(!$isSuper, fn ($q) => $q->where('tenant_id', $user->tenant_id));

        $query->when($request->filled('name'), function ($q) use ($request) {
            $names = is_array($request->name) ? $request->name : [$request->name];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (!empty($names)) {
                $q->where(function ($qq) use ($names) {
                    foreach ($names as $name) {
                        $qq->orWhere('name', 'like', '%' . $name . '%');
                    }
                });
            }
        });
        $query->when($request->filled('is_active'), function ($q) use ($request) {
            $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        });

        if (in_array($request->sort, ['id', 'name', 'is_active', 'created_at']) && in_array($request->direction, ['asc', 'desc'])) {
            $query->orderBy($request->sort, $request->direction);
        }

        $roles = $query->paginate($perPage)->withQueryString();

        // Marcamos cuales son del sistema para deshabilitar la edicion inline.
        $rolesArray = $roles->toArray();
        foreach ($rolesArray['data'] as &$row) {
            $row['is_system'] = in_array($row['name'], $this->systemRoleNames, true) && $row['tenant_id'] === null;
        }
        unset($row);

        return inertia('Roles/EditAll', [
            'roles'   => $rolesArray,
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

    public function editAllUpdate(EditAllUpdateRoleRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        $touched = 0;
        \DB::transaction(function () use ($data, $user, &$touched) {
            $ids  = array_column($data['changes'], 'id');
            $byId = Role::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($data['changes'] as $change) {
                $role = $byId[$change['id']] ?? null;
                if (!$role) continue;

                // Roles del sistema y los de otro tenant (si no es super) se saltean.
                if (in_array($role->name, $this->systemRoleNames, true) && $role->tenant_id === null) continue;
                if (!$user->hasRole('super') && $role->tenant_id !== $user->tenant_id) continue;

                $patch = array_intersect_key($change, array_flip(['name', 'description', 'is_active']));
                $patch = array_filter($patch, fn ($v) => $v !== null);
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $role->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $role->fill($patch)->save();
                $touched++;
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()
            ->route('user_management.roles.edit_all')
            ->with('success', __('global.updated_success') . " ({$touched})");
    }
}
