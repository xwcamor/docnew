<?php

namespace App\Imports\AuthManagement\Users;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Imports Users from .xlsx/.csv.
 *
 * Columns:
 *   - name      (required, max 255)
 *   - email     (required, max 255, globally unique → dedup key)
 *   - password  (optional; empty → auto-generated random 12-char password
 *               registered in the summary so el usuario sepa que debe resetear)
 *   - role_name (optional; default 'user'; must be assignable by dispatcher)
 *   - is_active (optional, boolean-ish; default true)
 *
 * Modes: 'create_only' | 'update_or_create'
 *   - create_only: si email ya existe, lo saltea.
 *   - update_or_create: si existe, patchea SOLO name + is_active + role
 *     (NO password — los passwords no se sobreescriben silenciosamente).
 *
 * 2-layer duplicate protection (email es la dedup key):
 *   1. In-file: case-insensitive contra emails ya vistos en el upload.
 *   2. DB: lookup `User::withoutGlobalScopes()->where('email', $email)`
 *      (sin scopes para detectar emails ya tomados aunque sean de otro tenant
 *      — users.email es unique global).
 *
 * Tenant scoping:
 *   - Imported users heredan el tenant_id del DISPATCHER. Si dispatcher es
 *     super (sin tenant), los users quedan sin tenant (eso es valido
 *     solo para super → no se recomienda; documentado).
 *   - El trait BelongsToTenant en User auto-fillea tenant_id en creating
 *     desde auth()->user()->tenant_id, asi que esta linea es backup.
 *
 * Enforce `Tenant::maxUsers()`:
 *   - Si el tenant tiene cap, contamos cuantos users activos hay HOY +
 *     cuantos vamos a CREAR. Si supera, marcamos las filas excedentes como
 *     error con `plans.limit_users_reached`. Las que actualizan no cuentan.
 *
 * Skipped automaticamente:
 *   - Filas cuyo email matchea system+*@system.local (system_users de tenants).
 *   - Filas con role_name='api' (proteccion explicita aunque ya estuviera
 *     filtrado por roleIsAssignable).
 *
 * Todo va en transaccion. dryRun=true → rollback al final (preview UI).
 */
class UsersImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, email:string, role_name:?string, is_active:bool, action:string, password_generated?:bool}> */
    public array $preview = [];

    /** Cache role_name (lowercase) → Role para evitar N queries en el loop. */
    protected array $roleCache = [];

    /** tenant_id del dispatcher (puede ser null si dispatcher es super). */
    protected ?int $tenantId;

    /** Plan limit del tenant en cantidad de users activos. */
    protected int $maxUsers;

    /** Count actual de users activos del tenant (pre-import). */
    protected int $currentUserCount;

    /** Dispatcher es super (para roleIsAssignable + bypass tenant). */
    protected bool $dispatcherIsSuper = false;

    /**
     * País/locale por defecto para los usuarios creados = los del dispatcher
     * (mismo workspace). users.country_id y users.locale_id son NOT NULL y el
     * template no los pide, así que se heredan de quien importa.
     */
    protected ?int $defaultCountryId = null;
    protected ?int $defaultLocaleId = null;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user = Auth::user();
        $this->tenantId               = $user?->tenant_id;
        $this->dispatcherIsSuper = $user
            ? $user->hasRole('super')
            : false;
        $this->defaultCountryId = $user?->country_id ?? \App\Models\Country::query()->value('id');
        $this->defaultLocaleId  = $user?->locale_id ?? \App\Models\Locale::query()->value('id');

        // Plan limit del tenant. super sin tenant_id → sin limite.
        if ($user && $user->tenant) {
            $this->maxUsers         = $user->tenant->maxUsers();
            $this->currentUserCount = $user->tenant->activeUserCount();
        } else {
            $this->maxUsers         = PHP_INT_MAX;
            $this->currentUserCount = 0;
        }

        // Precarga roles asignables. Para admin: roles del tenant + 'admin' global.
        // Para super: todos menos super.
        $rolesQuery = Role::query()->where('guard_name', 'web');
        if (!$this->dispatcherIsSuper) {
            $rolesQuery->where(function ($q) {
                $q->where('tenant_id', $this->tenantId)
                  ->orWhere(fn ($qq) => $qq->whereNull('tenant_id')->whereIn('name', ['admin', 'user']));
            });
        }
        $rolesQuery->where('name', '!=', 'super')
                   ->where('name', '!=', 'api');

        // OJO: incluir guard_name. Spatie lo necesita al asignar el rol; si se
        // omite, el modelo cacheado lo trae null y syncRoles() revienta con
        // GuardDoesNotMatch (givenGuard null).
        $rolesQuery->get(['id', 'name', 'tenant_id', 'guard_name'])->each(function ($r) {
            $this->roleCache[mb_strtolower($r->name)] = $r;
        });
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file por email normalizado.
            $seenInFileByEmail = [];

            // Contador de filas que crearian un nuevo user (para max_users).
            $newRecordsCount = 0;

            foreach ($rows as $i => $row) {
                $absoluteRow = $i + 2; // +2 = header (1) + indexacion desde 0.

                $name = $this->normalizeName($row['name'] ?? null);
                if ($name === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_required'),
                        'value'   => '—',
                    ];
                    continue;
                }
                if (mb_strlen($name) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_name_too_long'),
                        'value'   => mb_substr($name, 0, 60) . '…',
                    ];
                    continue;
                }

                $email = $this->normalizeEmail($row['email'] ?? null);
                if ($email === null) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'email es obligatorio.',
                        'value'   => $name,
                    ];
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'email invalido.',
                        'value'   => $email,
                    ];
                    continue;
                }
                if (mb_strlen($email) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'email supera los 255 caracteres.',
                        'value'   => mb_substr($email, 0, 60) . '…',
                    ];
                    continue;
                }

                // Saltear system_users (cuentas internas con rol api).
                if ($this->isSystemEmail($email)) {
                    $this->skipped++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'email'     => $email,
                        'role_name' => null,
                        'is_active' => true,
                        'action'    => 'skipped',
                    ];
                    continue;
                }

                if (isset($seenInFileByEmail[$email])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFileByEmail[$email]]),
                        'value'   => $email,
                    ];
                    continue;
                }
                $seenInFileByEmail[$email] = $absoluteRow;

                $rawPassword = $row['password'] ?? null;
                $passwordRaw = $rawPassword === null ? '' : trim((string) $rawPassword);
                $passwordGenerated = false;

                if ($passwordRaw === '') {
                    $passwordRaw = Str::random(12);
                    $passwordGenerated = true;
                } elseif (mb_strlen($passwordRaw) < 8) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'password debe tener al menos 8 caracteres.',
                        'value'   => $email,
                    ];
                    continue;
                }

                $roleNameRaw = $this->normalizeRoleName($row['role_name'] ?? null);
                $roleKey     = $roleNameRaw !== null ? mb_strtolower($roleNameRaw) : 'user';
                $role        = $this->roleCache[$roleKey] ?? null;

                // role_name explícito que no existe → error. EXCEPCIÓN: 'user'
                // es el centinela del default "usuario básico (sin rol elevado)"
                // — el template lo usa de ejemplo y no hay un rol 'user' seedeado,
                // así que lo tratamos como "sin rol" en vez de fallar (coherente
                // con dejar role_name vacío, que también crea al user sin rol).
                if ($roleNameRaw !== null && !$role && $roleKey !== 'user') {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'role_name no existe o no es asignable: ' . $roleNameRaw,
                        'value'   => $email,
                    ];
                    continue;
                }

                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                // Layer 2: DB lookup por email (sin scopes — unique global).
                $existing = User::withoutGlobalScopes()
                    ->whereRaw('LOWER(users.email) = LOWER(?)', [$email])
                    ->first();

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'       => $absoluteRow,
                            'name'      => $name,
                            'email'     => $email,
                            'role_name' => $role?->name,
                            'is_active' => $isActive,
                            'action'    => 'skipped',
                        ];
                        continue;
                    }

                    // Update mode: NO tocamos password (no se sobreescribe silenciosamente).
                    $patch = [];
                    if ($existing->name !== $name)                      $patch['name']      = $name;
                    if ((bool) $existing->is_active !== $isActive)      $patch['is_active'] = $isActive;
                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                    }
                    // Sync role si se especifico (y es distinto al actual).
                    if ($role) {
                        $currentRoleName = $existing->roles()->first()?->name;
                        if ($currentRoleName !== $role->name) {
                            $existing->syncRoles([$role]);
                        }
                    }

                    $this->updated++;
                    $this->preview[] = [
                        'row'       => $absoluteRow,
                        'name'      => $name,
                        'email'     => $email,
                        'role_name' => $role?->name,
                        'is_active' => $isActive,
                        'action'    => 'updated',
                    ];
                } else {
                    // Antes de crear, validar limite del plan.
                    if ($this->maxUsers > 0 && $this->maxUsers !== PHP_INT_MAX) {
                        if (($this->currentUserCount + $newRecordsCount) >= $this->maxUsers) {
                            $this->errors[] = [
                                'row'     => $absoluteRow,
                                'message' => __('plans.limit_users_reached', ['max' => $this->maxUsers]),
                                'value'   => $email,
                            ];
                            continue;
                        }
                    }

                    $newUser = new User([
                        'name'      => $name,
                        'email'     => $email,
                        'password'  => $passwordRaw, // cast 'hashed' del modelo aplica Hash::make.
                        'is_active' => $isActive,
                    ]);
                    // country_id / locale_id son NOT NULL y no vienen en el
                    // template: se heredan del dispatcher (mismo workspace).
                    $newUser->country_id = $this->defaultCountryId;
                    $newUser->locale_id  = $this->defaultLocaleId;
                    $newUser->created_by = Auth::id();
                    // tenant_id lo auto-fillea el trait BelongsToTenant desde auth.
                    // Pero por defensa explicita, lo seteamos tambien aquí.
                    if ($this->tenantId !== null) {
                        $newUser->tenant_id = $this->tenantId;
                    }
                    $newUser->save();

                    if ($role) {
                        $newUser->syncRoles([$role]);
                    }

                    $newRecordsCount++;
                    $this->created++;
                    $this->preview[] = [
                        'row'                => $absoluteRow,
                        'name'               => $name,
                        'email'              => $email,
                        'role_name'          => $role?->name,
                        'is_active'          => $isActive,
                        'action'             => 'created',
                        'password_generated' => $passwordGenerated,
                    ];
                }
            }

            if ($this->dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function summary(): array
    {
        // Contador de passwords auto-generados — info util para el frontend.
        $passwordsGenerated = 0;
        foreach ($this->preview as $p) {
            if (!empty($p['password_generated'])) $passwordsGenerated++;
        }

        return [
            'created'             => $this->created,
            'updated'             => $this->updated,
            'skipped'             => $this->skipped,
            'error_count'         => count($this->errors),
            'total_rows'          => $this->created + $this->updated + $this->skipped + count($this->errors),
            'errors'              => array_slice($this->errors, 0, 50),
            'preview'             => array_slice($this->preview, 0, 100),
            'dry_run'             => $this->dryRun,
            'passwords_generated' => $passwordsGenerated,
        ];
    }

    protected function normalizeName(mixed $value): ?string
    {
        if ($value === null) return null;
        $name = trim((string) $value);
        return $name === '' ? null : $name;
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        if ($value === null) return null;
        $email = mb_strtolower(trim((string) $value));
        return $email === '' ? null : $email;
    }

    protected function normalizeRoleName(mixed $value): ?string
    {
        if ($value === null) return null;
        $role = trim((string) $value);
        return $role === '' ? null : $role;
    }

    /** Detecta emails de cuentas internas: api+*@system.local */
    protected function isSystemEmail(string $email): bool
    {
        return preg_match('/^(api|system)\+.*@system\.local$/i', $email) === 1;
    }

    /** Acepta 1/0, true/false, si/no, activo/inactivo, yes/no, active/inactive, x. */
    protected function normalizeBool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') return $default;
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return ((int) $value) === 1;

        $normalized = mb_strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 't', 'yes', 'y', 'sí', 'si', 's', 'activo', 'active', 'x'], true);
    }
}
