<?php

// Source folder: app\Http\Controllers\AuthManagement\
namespace App\Http\Controllers\AuthManagement;

use App\Http\Controllers\Controller;
use App\Models\User;

// Requests
use App\Http\Requests\AuthManagement\User\StoreRequest;
use App\Http\Requests\AuthManagement\User\UpdateRequest;
use App\Http\Requests\AuthManagement\User\DeleteRequest;
use App\Http\Requests\AuthManagement\User\ImportUserRequest;

// Services
use App\Services\AuthManagement\UserService;

// Jobs
use App\Jobs\AuthManagement\Users\GenerateUsersCsvJob;
use App\Jobs\AuthManagement\Users\GenerateUsersExcelJob;
use App\Jobs\AuthManagement\Users\GenerateUsersPdfJob;
use App\Jobs\AuthManagement\Users\GenerateUsersWordJob;

// Illuminates
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Builder;

class UserController extends Controller
{
    use \App\Traits\BuildsRecordAudit;

    protected function parseAdvancedWhere(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('advanced_where', []);
        if (is_string($raw)) { $raw = json_decode($raw, true) ?: []; }
        if (!is_array($raw)) return [];
        return array_values(array_filter($raw, fn ($c) => is_array($c) && !empty($c['field']) && !empty($c['op'])));
    }

    // ─── INDEX (Inertia) ────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        if (! $request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'desc']);
        }

        $advanced = $this->parseAdvancedWhere($request);

        // Tenant scope is applied automatically by BelongsToTenant trait.
        // HideSuperScope hides super users from non-super viewers.
        // Columnas con prefijo `users.` por el JOIN de orderByFavoriteFirst.
        $viewerId = $request->user()?->id;
        $users = User::query()
            ->select('users.id', 'users.slug', 'users.name', 'users.email', 'users.photo', 'users.is_active', 'users.tenant_id', 'users.country_id', 'users.locale_id', 'users.timezone', 'users.created_at', 'users.updated_at', 'users.created_by')
            ->with(['creator:id,name,email', 'tenant:id,name', 'roles:id,name', 'country:id,name', 'locale:id,code,name'])
            ->orderByFavoriteFirst($viewerId)
            ->when($request->filled('name'), function (Builder $q) use ($request) {
                $names = is_array($request->name) ? $request->name : [$request->name];
                $names = array_filter($names, fn ($n) => $n !== '');
                if (! empty($names)) {
                    $isPgsql = config('database.default') === 'pgsql';
                    $q->where(function ($qq) use ($names, $isPgsql) {
                        foreach ($names as $name) {
                            if ($isPgsql) {
                                $qq->orWhereRaw('unaccent(lower(users.name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                            } else {
                                $qq->orWhere('users.name', 'like', '%' . $name . '%');
                            }
                        }
                    });
                }
            })
            ->when($request->filled('email'), fn ($q) => $q->where('users.email', 'like', '%' . $request->email . '%'))
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('users.is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->filled('role_id'), function ($q) use ($request) {
                // Filtra por rol asignado al user (vía Spatie pivot).
                $roleIds = is_array($request->role_id) ? $request->role_id : [$request->role_id];
                $q->whereHas('roles', fn ($r) => $r->whereIn('roles.id', $roleIds));
            })
            ->when($request->filled('tenant_id') && $request->user()->hasRole('super'), function ($q) use ($request) {
                // Solo super puede filtrar cross-tenant (admin ya está scoped por trait).
                $tenantIds = is_array($request->tenant_id) ? $request->tenant_id : [$request->tenant_id];
                $q->whereIn('users.tenant_id', $tenantIds);
            })
            ->when($request->filled('created_from'), fn ($q) => $q->where('users.created_at', '>=', $request->created_from . ' 00:00:00'))
            ->when($request->filled('created_to'),   fn ($q) => $q->where('users.created_at', '<=', $request->created_to . ' 23:59:59'))
            ->when(!empty($advanced), fn ($q) => \App\Services\Automations\Support\FilterApplier::apply($q, ['where' => $advanced], \App\Models\User::filterSchema()))
            ->when($request->filled('only_favorites') && filter_var($request->only_favorites, FILTER_VALIDATE_BOOLEAN) && $viewerId, function ($q) use ($viewerId) {
                $q->whereExists(function ($sub) use ($viewerId) {
                    $sub->select(\DB::raw(1))
                        ->from('user_favorites')
                        ->whereColumn('user_favorites.favoritable_id', 'users.id')
                        ->where('user_favorites.favoritable_type', User::class)
                        ->where('user_favorites.user_id', $viewerId);
                });
            })
            ->when(in_array($request->sort, ['id', 'name', 'email', 'is_active', 'created_at']) && in_array($request->direction, ['asc', 'desc']),
                fn ($q) => $q->orderBy('users.' . $request->sort, $request->direction))
            // Orden por perfil (rol): el rol viene de la relación, así que se
            // ordena por el nombre del rol vía subconsulta correlacionada.
            ->when($request->sort === 'role' && in_array($request->direction, ['asc', 'desc']),
                fn ($q) => $q->orderByRaw(
                    '(select roles.name from roles'
                    . ' inner join model_has_roles on model_has_roles.role_id = roles.id'
                    . ' where model_has_roles.model_id = users.id and model_has_roles.model_type = ?'
                    . ' limit 1) ' . ($request->direction === 'desc' ? 'desc' : 'asc'),
                    [User::class]
                ))
            // Orden por workspace (tenant): nombre vía subconsulta correlacionada
            // por tenant_id (nulls = global → quedan al final/principio según dir).
            ->when($request->sort === 'tenant' && in_array($request->direction, ['asc', 'desc']),
                fn ($q) => $q->orderByRaw(
                    '(select tenants.name from tenants where tenants.id = users.tenant_id) '
                    . ($request->direction === 'desc' ? 'desc' : 'asc')
                ))
            // Orden por país: nombre vía subconsulta correlacionada por country_id.
            ->when($request->sort === 'country' && in_array($request->direction, ['asc', 'desc']),
                fn ($q) => $q->orderByRaw(
                    '(select countries.name from countries where countries.id = users.country_id) '
                    . ($request->direction === 'desc' ? 'desc' : 'asc')
                ))
            ->paginate($perPage)
            ->withQueryString();

        // Total unfiltered for the adaptive counter.
        $totalUnfiltered = User::query()->count();

        // Normalize 'name' to always be an array (FilterBar uses tags).
        $names = $request->get('name', []);
        if (is_string($names)) {
            $names = $names === '' ? [] : [$names];
        }

        $isSuper = $request->user()->hasRole('super');

        // slug se mantiene SIEMPRE en el payload — el frontend lo necesita para
        // rutear (edit/delete/show usan slug). NO se muestra como dato/columna
        // (eso lo gatea el frontend), pero el dato debe viajar o route() revienta.
        $usersArray = $users->toArray();
        foreach ($usersArray['data'] as &$row) {
            $row['role'] = isset($row['roles'][0]) ? $row['roles'][0]['name'] : null;
            unset($row['roles']);
        }
        unset($row);

        return inertia('Users/Index', [
            'users' => array_merge($usersArray, [
                'total_unfiltered' => $totalUnfiltered,
            ]),
            // Limites de export por formato — el frontend deshabilita formatos
            // que exceden su limite. CSV con 0 = sin limite (streaming).
            'exportLimits' => \App\Models\Setting::getExportLimits('users'),
            'filters' => [
                'name'         => array_values($names),
                'email'        => $request->get('email', ''),
                'is_active'    => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'role_id'      => $request->get('role_id', []),
                'tenant_id'    => $request->get('tenant_id', []),
                'created_from'   => $request->get('created_from', ''),
                'created_to'     => $request->get('created_to', ''),
                'only_favorites' => $request->boolean('only_favorites'),
                'advanced_where' => $advanced,
                'sort'           => $request->get('sort', 'id'),
                'direction'      => $request->get('direction', 'desc'),
                'per_page'       => $perPage,
            ],
            'roleOptions'   => $this->roleOptionsFor($request->user()),
            'tenantOptions' => $this->tenantOptionsFor($request->user()),
            'filterSchema'  => \App\Models\User::filterSchema(),
            'isSuper'       => $isSuper,
        ]);
    }

    // ─── Bulk ops ──────────────────────────────────────────────────────────
    public function bulkDelete(Request $request, UserService $service)
    {
        $data = $request->validate([
            'ids'                 => ['required', 'array', 'min:1'],
            'ids.*'               => ['integer'],
            'deleted_description' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $user = $request->user();
        $allowedIds = User::whereIn('id', $data['ids'])
            ->when(!$user->hasRole('super'), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            ->where('id', '!=', $user->id) // no permitir auto-delete masivo
            ->pluck('id')->all();

        $result = $service->bulkDelete($allowedIds, $data['deleted_description']);

        if (!empty($result['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        if (!empty($allowedIds)) {
            $this->storeUndoableDelete($allowedIds);
        }

        return back()
            ->with('success', __('global.bulk_deleted', ['count' => count($result['deleted'])], 'Eliminados: :count.'))
            ->with('recentDelete', $this->buildRecentDeletePayload($allowedIds));
    }

    public function bulkSetActive(Request $request, UserService $service)
    {
        $data = $request->validate([
            'ids'       => ['required', 'array', 'min:1'],
            'ids.*'     => ['integer'],
            'is_active' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $isDeactivating = !((bool) $data['is_active']);

        $allowedIds = User::whereIn('id', $data['ids'])
            ->when(!$user->hasRole('super'), fn ($q) => $q->where('tenant_id', $user->tenant_id))
            // Si la operación es DESACTIVAR, excluir al propio user del batch.
            // Sin esto un admin puede dejarse fuera del sistema mandando su id
            // con is_active=false. Para activar masivamente sí permitimos incluirse.
            ->when($isDeactivating, fn ($q) => $q->where('id', '!=', $user->id))
            ->pluck('id')->all();

        $result = $service->bulkSetActive($allowedIds, (bool) $data['is_active']);

        if (!empty($result['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return back()->with('success', __('global.bulk_updated', ['count' => $result['changed']]));
    }

    public function bulkRestore(Request $request, UserService $service)
    {
        abort_unless($request->user()->hasRole('super'), 403);

        $data = $request->validate([
            'ids'   => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $result = $service->bulkRestore($data['ids']);

        if (!empty($result['queued'])) {
            return back()->with('success', __('global.bulk_in_queue', ['count' => $result['count']]));
        }

        return back()->with('success', __('global.bulk_restored', ['count' => $result['restored']], 'Restaurados: :count.'));
    }

    // ─── CREATE ─────────────────────────────────────────────────────────────
    public function create(Request $request)
    {
        return inertia('Users/Form', [
            'user'          => null,
            'roleOptions'   => $this->roleOptionsFor($request->user()),
            'tenantOptions' => $this->tenantOptionsFor($request->user()),
            'countryOptions'=> $this->countryOptions(),
            'localeOptions' => $this->localeOptions(),
            'canScopeCustomers' => $this->canScopeCustomers($request->user()),
            'customerOptions'   => $this->customerOptionsFor($request->user()),
        ]);
    }

    public function store(StoreRequest $request, UserService $service)
    {
        // Plan gate: limita cuántos usuarios puede tener el tenant según su
        // plan. El tope sale de Tenant::maxUsers() (DB-authoritative, lee
        // plans.max_users). super bypasses este gate cuando crea users
        // para otro tenant.
        $tenantId = $request->integer('tenant_id') ?: $request->user()?->tenant_id;
        if ($tenantId && !$request->user()?->hasRole('super')) {
            $targetTenant = \App\Models\Tenant::find($tenantId);
            if ($targetTenant && !$targetTenant->canCreateUser()) {
                return back()
                    ->withInput()
                    ->with('error', __('plans.limit_users_reached', ['max' => $targetTenant->maxUsers()]));
            }
        }

        $created = $service->create($request->validated(), $request->file('photo'));

        // Asignar rol si vino en el payload (admin_empresarial siempre asigna
        // un rol, super opcional).
        if ($request->filled('role_id')) {
            $role = \App\Models\Role::find($request->integer('role_id'));
            if ($role && $this->canAssignRole($request->user(), $role)) {
                $created->syncRoles([$role]);
            }
        }

        $this->syncAssignedCustomers($request, $created);

        // Bienvenida en la campana (para TODOS, sin importar el rol): recuerda
        // cambiar la contraseña inicial. Se renderiza en el idioma del usuario.
        $iso = optional(\App\Models\Locale::with('language')->find($created->locale_id))
            ->language?->iso_code ?? config('app.locale', 'es');
        $created->notify((new \App\Notifications\WelcomeUser())->locale($iso));

        return redirect()
            ->route('user_management.users.index')
            ->with('success', __('global.created_success'));
    }

    // ─── Helpers para opciones del Form ─────────────────────────────────────

    /**
     * ¿El actor puede restringir usuarios a clientes? (3ª capa de acceso,
     * feature de plan `customer_scoped_users`). Super siempre puede.
     */
    protected function canScopeCustomers($actor): bool
    {
        return $actor->hasRole('super')
            || \App\Support\FeatureGate::allows('customer_scoped_users', $actor);
    }

    /**
     * Clientes asignables para el multiselect "Clientes visibles". El scope
     * BelongsToTenant ya limita a los del workspace del actor (super ve todos).
     */
    protected function customerOptionsFor($actor): array
    {
        if (!$this->canScopeCustomers($actor)) {
            return [];
        }
        return \App\Models\Customer::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => mb_strtoupper($c->name)])
            ->all();
    }

    /**
     * Sincroniza la restricción usuario↔clientes. Solo si el actor tiene el
     * feature; los ids se intersectan con los clientes VISIBLES para el actor
     * (anti-IDOR: un admin no puede asignar clientes de otro tenant). Lista
     * vacía = quitar la restricción (el usuario vuelve a ver todo su tenant).
     */
    protected function syncAssignedCustomers(Request $request, User $user): void
    {
        // OJO: el form de usuarios sube con FormData (por la foto) y un array
        // VACÍO no se serializa → al "limpiar" todos los clientes, el campo
        // `assigned_customer_ids` no llega. Por eso el form manda SIEMPRE el flag
        // escalar `assigned_customers_touched`; con él (o con el array presente,
        // p.ej. callers JSON) sabemos que hubo que sincronizar.
        $submitted = $request->has('assigned_customer_ids')
            || $request->boolean('assigned_customers_touched');
        if (!$submitted || !$this->canScopeCustomers($request->user())) {
            return;
        }

        $ids = array_filter(array_map('intval', (array) $request->input('assigned_customer_ids', [])));
        if ($ids) {
            $visible = \App\Models\Customer::whereIn('id', $ids)->pluck('id')->all();
            $ids = array_values(array_intersect($ids, $visible));
        }

        $user->assignedCustomers()->sync($ids);
    }

    protected function roleOptionsFor($actor): array
    {
        $isSuper = $actor->hasRole('super');
        $query = \App\Models\Role::query()->where('guard_name', 'web');

        if ($isSuper) {
            // super puede asignar cualquier rol excepto super (no se delega).
            $query->where('name', '!=', 'super');
        } else {
            // admin asigna:
            //   - los roles custom de SU tenant (perfiles que el mismo creo)
            //   - los roles GLOBALES (tenant_id null) que el super define como
            //     plantilla para todos: el `admin` global + cualquier rol global
            //     custom. NO super (no se delega) ni api (es para system_users).
            $query->where(function ($q) use ($actor) {
                $q->where('tenant_id', $actor->tenant_id)
                  ->orWhere(fn ($qq) => $qq->whereNull('tenant_id')->whereNotIn('name', ['super', 'api']));
            });
        }

        return $query->orderBy('name')->get(['id', 'name', 'description', 'tenant_id'])
            ->map(fn ($r) => [
                'value'       => $r->id,
                'label'       => $r->name,
                // La descripción va aparte: el form la muestra debajo del select
                // (en el label inline no se alcanzaba a leer).
                'description' => $r->description,
            ])->all();
    }

    protected function canAssignRole($actor, $role): bool
    {
        if ($role->name === 'super') return false;
        if ($actor->hasRole('super')) return true;
        // Admin asigna: roles de SU tenant + los GLOBALES (tenant_id null) que el
        // super publica como plantilla (el `admin` global + cualquier perfil
        // global custom). NO `api` (interno de tokens). Debe coincidir con el
        // dropdown de roleOptionsFor, sino el update guarda pero no sincroniza.
        if ($role->tenant_id === $actor->tenant_id) return true;
        if ($role->tenant_id === null && $role->name !== 'api') return true;
        return false;
    }

    protected function tenantOptionsFor($actor): array
    {
        if (!$actor->hasRole('super')) return [];
        return \App\Models\Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])
            ->all();
    }

    protected function countryOptions(): array
    {
        return \App\Models\Country::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name])
            ->all();
    }

    protected function localeOptions(): array
    {
        return \App\Models\Locale::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn ($l) => ['value' => $l->id, 'label' => $l->code . ' — ' . $l->name])
            ->all();
    }

    // ─── SHOW (Inertia) ─────────────────────────────────────────────────────
    public function show(Request $request, $slug)
    {
        $isSuper = $request->user()?->hasRole('super') ?? false;

        $query = User::query()->with(['creator:id,name,email', 'deleter:id,name,email', 'roles:id,name', 'country:id,name', 'locale:id,code,name']);
        if ($isSuper) {
            $query->withTrashed();
        }

        $user = $query->where('slug', $slug)->firstOrFail();

        // Historial de auditoria — solo super/admin lo ven (igual que Regions).
        $canSeeAudit = $request->user()?->hasAnyRole(['super', 'admin']) ?? false;
        $activity = $canSeeAudit
            ? \App\Http\Resources\AuditLogResource::collection(
                \App\Models\AuditLog::query()
                    ->where('auditable_type', User::class)
                    ->where('auditable_id', $user->id)
                    ->with('user:id,name,email')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get(['id', 'user_id', 'event', 'old_values', 'new_values', 'created_at'])
            )->resolve()
            : [];

        return inertia('Users/Show', [
            'user' => [
                'id'                  => $user->id,
                // slug SIEMPRE presente — es necesario para construir las
                // URLs de Edit/Delete en EntityShowActions. Sin slug, el
                // componente no puede armar el route() y Ziggy tira "Missing
                // required parameter" → la pagina entera no renderiza.
                'slug'                => $user->slug,
                'name'                => $user->name,
                'email'               => $user->email,
                'photo_url'           => $user->photo_url,
                'is_active'           => $user->is_active,
                'role'                => $user->roles->first()?->name,
                'country'             => $user->country ? ['name' => $user->country->name] : null,
                'locale'              => $user->locale ? ['code' => $user->locale->code, 'name' => $user->locale->name] : null,
                'timezone'            => $user->timezone,
                'created_at'          => $user->created_at,
                'updated_at'          => $user->updated_at,
                'deleted_at'          => $user->deleted_at,
                'deleted_description' => $user->deleted_description,
                'creator' => $user->creator ? [
                    'name'  => $user->creator->name,
                    'email' => $user->creator->email,
                ] : null,
                'deleter' => $user->deleter ? [
                    'name'  => $user->deleter->name,
                    'email' => $user->deleter->email,
                ] : null,
            ],
            'activity'     => $activity,
            'recordAudit'  => $this->recordAuditMeta($user),
            'isSuper'      => $isSuper,
        ]);
    }

    // ─── EDIT ───────────────────────────────────────────────────────────────
    public function edit(Request $request, User $user)
    {
        return inertia('Users/Form', [
            'user' => [
                'id'         => $user->id,
                'slug'       => $user->slug,
                'name'       => $user->name,
                'email'      => $user->email,
                'tenant_id'  => $user->tenant_id,
                'country_id' => $user->country_id,
                'locale_id'  => $user->locale_id,
                'role_id'    => $user->roles()->first()?->id,
                'photo_url'  => $user->photo_url,
                'is_active'  => $user->is_active,
                'assigned_customer_ids' => $user->assignedCustomerIds(),
            ],
            'roleOptions'   => $this->roleOptionsFor($request->user()),
            'tenantOptions' => $this->tenantOptionsFor($request->user()),
            'countryOptions'=> $this->countryOptions(),
            'localeOptions' => $this->localeOptions(),
            'canScopeCustomers' => $this->canScopeCustomers($request->user()),
            'customerOptions'   => $this->customerOptionsFor($request->user()),
        ]);
    }

    public function update(UpdateRequest $request, User $user, UserService $service)
    {
        $service->update($user, $request->validated(), $request->file('photo'));

        // Sync rol si vino — admin solo puede asignar roles de su tenant.
        if ($request->has('role_id')) {
            $role = $request->filled('role_id')
                ? \App\Models\Role::find($request->integer('role_id'))
                : null;
            if ($role && $this->canAssignRole($request->user(), $role)) {
                $user->syncRoles([$role]);
            }
        }

        $this->syncAssignedCustomers($request, $user);

        return redirect()
            ->route('user_management.users.index')
            ->with('success', __('global.updated_success'));
    }

    // ─── DELETE ─────────────────────────────────────────────────────────────
    public function delete(User $user)
    {
        return inertia('Users/Delete', [
            'user' => [
                'id'    => $user->id,
                'slug'  => $user->slug,
                'name'  => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
            ],
        ]);
    }

    public function deleteSave(DeleteRequest $request, User $user, UserService $service)
    {
        $service->delete($user, $request->deleted_description);

        $this->storeUndoableDelete([$user->id]);

        return redirect()
            ->route('user_management.users.index')
            ->with('success', __('global.deleted_success'))
            ->with('recentDelete', $this->buildRecentDeletePayload([$user->id]));
    }

    /** Persiste el claim en sesion por el window de undo (60s). */
    protected function storeUndoableDelete(array $ids): void
    {
        session(['users.recent_delete' => [
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
     * Undo dentro del window de 60s. UNDO != papelera: red de seguridad
     * inmediata para quien borro. Lo puede hacer el admin (no requiere
     * super). Defense in depth: validamos deleted_by = current user.
     */
    public function undoLastDelete(Request $request, UserService $service)
    {
        $claim = session('users.recent_delete');
        if (!$claim || empty($claim['ids']) || empty($claim['expires_at'])) {
            return back()->with('error', __('global.undo_failed'));
        }
        if (now()->isAfter($claim['expires_at'])) {
            session()->forget('users.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        $userId = $request->user()?->id;
        $users = User::onlyTrashed()
            ->whereIn('id', $claim['ids'])
            ->where('deleted_by', $userId)
            ->get();

        if ($users->isEmpty()) {
            session()->forget('users.recent_delete');
            return back()->with('error', __('global.undo_failed'));
        }

        foreach ($users as $u) {
            $service->restore($u);
        }
        session()->forget('users.recent_delete');

        return back()->with('success', __('global.undo_done'));
    }

    // ─── TRASH + RESTORE — SUPER ONLY ──────────────────────────────────────
    public function trash(Request $request)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        $users = User::onlyTrashed()
            ->with(['deleter:id,name,email'])
            ->select('id', 'slug', 'name', 'email', 'is_active', 'deleted_at', 'deleted_by', 'deleted_description', 'created_at')
            ->when($request->filled('name'), function ($q) use ($request) {
                $name = $request->get('name');
                $isPgsql = config('database.default') === 'pgsql';
                if ($isPgsql) {
                    $q->whereRaw('unaccent(lower(name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                } else {
                    $q->where('name', 'like', '%' . $name . '%');
                }
            })
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Users/Trash', [
            'users'   => $users,
            'filters' => [
                'name'     => $request->get('name', ''),
                'per_page' => $perPage,
            ],
        ]);
    }

    public function restore(Request $request, string $slug, UserService $service)
    {
        abort_unless($request->user()?->hasRole('super'), 403);

        $user = User::onlyTrashed()->where('slug', $slug)->firstOrFail();
        $service->restore($user);

        return redirect()
            ->route('user_management.users.trash')
            ->with('success', __('global.restored_success', ['default' => 'Usuario restaurado.']));
    }

    /**
     * Hard-delete con triple guard: super + onlyTrashed + nombre exacto.
     * Audit log se escribe antes del delete físico para que sobreviva.
     */
    public function forceDelete(\App\Http\Requests\AuthManagement\User\ForceDeleteRequest $request, string $slug)
    {
        $data = $request->validated();

        // Lookup + name match fuera del lock (no muta nada). El forceDelete
        // y su audit van dentro de una transacción con lockForUpdate para
        // evitar una race: dos requests concurrentes podrían pasar el lookup
        // y ambos hacer forceDelete, o uno restaurar mientras el otro borra.
        $userPreview = User::onlyTrashed()->where('slug', $slug)->firstOrFail();
        if (trim($data['name_confirmation']) !== $userPreview->name) {
            return back()->withErrors([
                'name_confirmation' => __('global.force_delete_name_mismatch'),
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($slug, $data, $request) {
            // Re-fetch DENTRO de la tx con lockForUpdate. Si entre el preview
            // y este lookup alguien restauró el user, onlyTrashed() falla
            // con 404 y abortamos sin daño.
            $user = User::onlyTrashed()->where('slug', $slug)->lockForUpdate()->firstOrFail();

            \App\Models\AuditLog::create([
                'user_id'        => $request->user()?->id,
                'auditable_type' => User::class,
                'auditable_id'   => $user->id,
                'event'          => 'force_deleted',
                'old_values'     => ['name' => $user->name, 'email' => $user->email, 'slug' => $user->slug],
                'new_values'     => null,
                'note'           => $data['reason'],
                'module'         => 'users',
                'created_at'     => now(),
            ]);
            $user->forceDelete();
        });

        return redirect()
            ->route('user_management.users.trash')
            ->with('success', __('global.force_deleted_success'));
    }

    // ── EXPORTS ─────────────────────────────────────────────────────────────
    // Los 4 formatos van a queue como jobs async (mismo patron que Customers).
    // El job se encarga de la query con scope + render + Download record.

    public function exportCsv(Request $request)
    {
        return $this->dispatchExport($request, 'csv', GenerateUsersCsvJob::class);
    }

    public function exportExcel(Request $request)
    {
        return $this->dispatchExport($request, 'excel', GenerateUsersExcelJob::class);
    }

    public function exportPdf(Request $request)
    {
        return $this->dispatchExport($request, 'pdf', GenerateUsersPdfJob::class);
    }

    public function exportWord(Request $request)
    {
        return $this->dispatchExport($request, 'word', GenerateUsersWordJob::class);
    }

    /**
     * Helper comun de los 4 export endpoints: parse options → limit check →
     * audit → dispatch. Mismo patron que Customers.
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

        $limit = \App\Models\Setting::getExportLimit('users', $format);
        if ($limit === 0) return; // CSV streaming, sin limite

        $count = $this->countForExport($options);
        if ($count > $limit) {
            abort(422, __('users.export_limit_exceeded', [
                'count'  => number_format($count),
                'limit'  => number_format($limit),
                'format' => strtoupper($format),
            ]));
        }
    }

    /**
     * Cuenta filas a exportar segun scope+filters. Replica logica de
     * BaseUserExportJob::buildQuery (sin eager loads) para el count check.
     */
    protected function countForExport(array $options): int
    {
        $scope = $options['scope'] ?? 'filtered';

        // El conteo lo hacemos aplicando el scope normal: aquí SI tenemos auth,
        // asi que BelongsToTenant + HideSuperScope cubren el tenant filter.
        $base = User::query();

        if ($scope === 'selected') {
            return count($options['selected_ids'] ?? []);
        }
        if ($scope === 'all') {
            return $base->count();
        }

        // Replica filter logic (User no tiene scopeFilter).
        $f = $options['filters'] ?? [];

        if (!empty($f['name'])) {
            $names = is_array($f['name']) ? $f['name'] : [$f['name']];
            $names = array_filter($names, fn ($n) => $n !== '');
            if (!empty($names)) {
                $isPgsql = config('database.default') === 'pgsql';
                $base->where(function ($qq) use ($names, $isPgsql) {
                    foreach ($names as $name) {
                        if ($isPgsql) {
                            $qq->orWhereRaw('unaccent(lower(users.name)) LIKE unaccent(lower(?))', ['%' . $name . '%']);
                        } else {
                            $qq->orWhere('users.name', 'like', '%' . $name . '%');
                        }
                    }
                });
            }
        }
        if (!empty($f['email'])) {
            $base->where('users.email', 'like', '%' . $f['email'] . '%');
        }
        if (isset($f['is_active']) && $f['is_active'] !== '' && $f['is_active'] !== null) {
            $base->where('users.is_active', filter_var($f['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (!empty($f['role_id'])) {
            $roleIds = is_array($f['role_id']) ? $f['role_id'] : [$f['role_id']];
            $base->whereHas('roles', fn ($r) => $r->whereIn('roles.id', $roleIds));
        }
        if (!empty($f['tenant_id']) && auth()->user()?->hasRole('super')) {
            $tenantIds = is_array($f['tenant_id']) ? $f['tenant_id'] : [$f['tenant_id']];
            $base->whereIn('users.tenant_id', $tenantIds);
        }
        if (!empty($f['created_from'])) {
            $base->where('users.created_at', '>=', $f['created_from'] . ' 00:00:00');
        }
        if (!empty($f['created_to'])) {
            $base->where('users.created_at', '<=', $f['created_to'] . ' 23:59:59');
        }

        return $base->count();
    }

    // ── IMPORTS (two-phase: dry_run preview + commit) ────────────────────
    // El frontend sube 2 veces: primero dry_run=true (preview con summary),
    // despues dry_run=false (commit).

    public function importTemplate()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AuthManagement\Users\UsersImportTemplate(),
            __('users.import_template_filename')
        );
    }

    public function import(ImportUserRequest $request)
    {
        $data   = $request->validated();
        $mode   = $data['mode'] ?? 'update_or_create';
        $dryRun = filter_var($data['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $importer = new \App\Imports\AuthManagement\Users\UsersImport(
            mode:   $mode,
            dryRun: $dryRun,
        );

        try {
            \Maatwebsite\Excel\Facades\Excel::import($importer, $data['file']);
        } catch (\Throwable $e) {
            \Log::error('UsersImport failed', [
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
        // La columna `tenant` (workspace) SOLO es exportable por super: el resto
        // de roles ve unicamente users de su propio tenant, exponerla seria
        // redundante y filtraria el nombre del workspace. Gate de seguridad real
        // (no basta con ocultarla en el ExportDialog del front).
        $isSuper = $request->user()?->hasRole('super') ?? false;
        $allowedColumns = array_values(array_filter([
            'name', 'email', 'role',
            $isSuper ? 'tenant' : null,
            'is_active', 'created_at', 'updated_at', 'creator', 'photo',
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
            'title'                   => $data['title']                   ?? __('users.export_title'),
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
        \App\Models\AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'export_queued',
            'auditable_type' => User::class,
            'auditable_id'   => null,
            'module'         => 'users',
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
            'url'        => route('user_management.users.index'),
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    // ── EDIT ALL ────────────────────────────────────────────────────────────
    // Batch edit de name + is_active. El email NO se edita en batch (es unico
    // y sensible — se cambia uno por uno desde el form). super queda
    // fuera del listado via HideSuperScope. Transaccional.

    public function editAll(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        if (!$request->filled('sort')) {
            $request->merge(['sort' => 'id', 'direction' => 'asc']);
        }

        $users = User::query()
            ->select('id', 'slug', 'name', 'email', 'is_active')
            ->when($request->filled('name'), function ($q) use ($request) {
                $names = is_array($request->name) ? $request->name : [$request->name];
                $names = array_filter($names, fn ($n) => $n !== '');
                if (!empty($names)) {
                    $q->where(function ($qq) use ($names) {
                        foreach ($names as $name) {
                            $qq->orWhere('name', 'like', '%' . $name . '%');
                        }
                    });
                }
            })
            ->when($request->filled('is_active'), function ($q) use ($request) {
                $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when(in_array($request->sort, ['id', 'name', 'email', 'is_active']) && in_array($request->direction, ['asc', 'desc']),
                fn ($q) => $q->orderBy($request->sort, $request->direction))
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Users/EditAll', [
            'users'   => $users,
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

    public function editAllUpdate(Request $request)
    {
        $data = $request->validate([
            'changes'             => 'required|array|min:1|max:200',
            'changes.*.id'        => 'required|integer',
            'changes.*.name'      => 'sometimes|string|max:255',
            'changes.*.is_active' => 'sometimes|boolean',
        ]);

        $touched = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, &$touched) {
            $ids  = array_column($data['changes'], 'id');
            // El scope BelongsToTenant + HideSuperScope ya limita el set
            // a los users que el viewer puede tocar.
            $byId = User::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($data['changes'] as $change) {
                $u = $byId[$change['id']] ?? null;
                if (!$u) continue;

                $patch = array_intersect_key($change, array_flip(['name', 'is_active']));
                $patch = array_filter($patch, fn ($v) => $v !== null);
                if (empty($patch)) continue;

                $hasChange = false;
                foreach ($patch as $k => $v) {
                    if ((string) $u->{$k} !== (string) $v) { $hasChange = true; break; }
                }
                if (!$hasChange) continue;

                $u->fill($patch)->save();
                $touched++;
            }
        });

        return redirect()
            ->route('user_management.users.edit_all')
            ->with('success', __('global.updated_success') . " ({$touched})");
    }
}
