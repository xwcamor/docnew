<?php

namespace App\Imports\AuthManagement\Roles;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Imports Roles desde .xlsx/.csv.
 *
 * Columnas:
 *   - name         (required, max 120)
 *   - description  (optional, max 255)
 *   - is_active    (optional, boolean-ish; default true)
 *   - permissions  (optional, lista separada por comas: "customers.view,customers.edit")
 *
 * Modes: 'create_only' | 'update_or_create'
 *
 * 3-layer duplicate protection (todo dentro del tenant scope):
 *   1. In-file: normalizado (trim+lower+iconv) catchea dupes en el mismo upload
 *   2. App: lookup case + accent insensitive contra DB del tenant
 *   3. DB: la unica natural (name + guard_name + tenant_id)
 *
 * Tenant scoping:
 *   - Los roles importados SIEMPRE se asignan al tenant del usuario (Role NO
 *     usa BelongsToTenant — seteamos tenant_id explicito).
 *   - Para super (sin tenant_id) los roles quedan globales (tenant_id=null).
 *     PERO los roles globales del sistema (super/admin/api) NO se pueden
 *     crear via import — esos son seeded. Si super importa con nombre
 *     coincidente, va a error.
 *   - is_system se setea siempre false (no es columna del modelo de hecho —
 *     se computa por name + tenant_id null).
 *
 * Todo va en transaccion. dryRun=true → rollback al final (preview UI).
 */
class RolesImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;
    public int $updated = 0;
    public int $skipped = 0;

    /** @var array<int, array{row:int, message:string, value?:string}> */
    public array $errors = [];

    /** @var array<int, array{row:int, name:string, description:?string, is_active:bool, permissions:array, action:string}> */
    public array $preview = [];

    /** Cache permission name → id (case-sensitive — los permission names son canonicos). */
    protected array $permissionCache = [];

    /** Nombres protegidos del sistema — no se pueden crear via import. */
    protected array $systemRoleNames = ['super', 'admin', 'api'];

    /** tenant_id del usuario autenticado, capturado al construir. */
    protected ?int $tenantId;

    public function __construct(
        protected string $mode = 'update_or_create',
        protected bool $dryRun = false,
    ) {
        $user           = Auth::user();
        $this->tenantId = $user?->tenant_id;

        // Precarga permission_name → id en una sola query (filtrado por guard web).
        Permission::query()
            ->where('guard_name', 'web')
            ->get(['id', 'name'])
            ->each(function ($p) {
                $this->permissionCache[$p->name] = $p->id;
            });
    }

    public function collection(Collection $rows): void
    {
        DB::beginTransaction();

        try {
            // Layer 1: dedup in-file por nombre normalizado.
            $seenInFileByName = [];

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
                if (mb_strlen($name) > 120) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'El nombre supera los 120 caracteres.',
                        'value'   => mb_substr($name, 0, 60) . '…',
                    ];
                    continue;
                }

                // Roles del sistema (super/admin/api) no se pueden crear
                // ni patchar via import — son seeded.
                if (in_array($name, $this->systemRoleNames, true)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'No se permite importar roles del sistema (super/admin/api).',
                        'value'   => $name,
                    ];
                    continue;
                }

                $normNameKey = $this->normalizeKey($name);
                if (isset($seenInFileByName[$normNameKey])) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => __('imports.err_duplicate_in_file', ['row' => $seenInFileByName[$normNameKey]]),
                        'value'   => $name,
                    ];
                    continue;
                }
                $seenInFileByName[$normNameKey] = $absoluteRow;

                $description = $this->normalizeDescription($row['description'] ?? null);
                if ($description !== null && mb_strlen($description) > 255) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'La descripcion supera los 255 caracteres.',
                        'value'   => mb_substr($description, 0, 60) . '…',
                    ];
                    continue;
                }

                $isActive = $this->normalizeBool($row['is_active'] ?? null, default: true);

                // Parse permissions: lista separada por comas. Validamos cada
                // nombre contra el cache. Desconocidos → error de fila completa.
                [$permIds, $unknownPerms] = $this->resolvePermissions($row['permissions'] ?? null);
                if (!empty($unknownPerms)) {
                    $this->errors[] = [
                        'row'     => $absoluteRow,
                        'message' => 'Permisos desconocidos: ' . implode(', ', $unknownPerms),
                        'value'   => $name,
                    ];
                    continue;
                }

                // Layer 2: DB lookup case + accent insensitive (scoped al tenant).
                $existing = $this->findExistingByNameInsensitive($name);

                if ($existing) {
                    if ($this->mode === 'create_only') {
                        $this->skipped++;
                        $this->preview[] = [
                            'row'         => $absoluteRow,
                            'name'        => $name,
                            'description' => $description,
                            'is_active'   => $isActive,
                            'permissions' => $this->permissionNamesFromIds($permIds),
                            'action'      => 'skipped',
                        ];
                        continue;
                    }

                    // update_or_create: patchear description + is_active.
                    // NUNCA tocamos name (es la clave de match) ni tenant_id.
                    $patch = [];
                    if ((bool) $existing->is_active !== $isActive)                 $patch['is_active']   = $isActive;
                    if ($description !== null && $existing->description !== $description) $patch['description'] = $description;
                    if (!empty($patch)) {
                        $existing->fill($patch)->save();
                    }

                    // Sincroniza permissions (incluso si no hay otros cambios —
                    // el usuario podria estar importando solo para resetear
                    // los permisos de un perfil existente).
                    if (!empty($permIds) || $description !== null || isset($row['permissions'])) {
                        $perms = Permission::whereIn('id', $permIds)->get();
                        $existing->syncPermissions($perms);
                    }

                    $this->updated++;
                    $this->preview[] = [
                        'row'         => $absoluteRow,
                        'name'        => $name,
                        'description' => $description,
                        'is_active'   => $isActive,
                        'permissions' => $this->permissionNamesFromIds($permIds),
                        'action'      => 'updated',
                    ];
                } else {
                    // Crear nuevo role en el tenant del usuario.
                    $role = Role::create([
                        'name'        => $name,
                        'description' => $description,
                        'guard_name'  => 'web',
                        'tenant_id'   => $this->tenantId,
                        'is_active'   => $isActive,
                        'created_by'  => Auth::id(),
                    ]);

                    if (!empty($permIds)) {
                        $perms = Permission::whereIn('id', $permIds)->get();
                        $role->syncPermissions($perms);
                    }

                    $this->created++;
                    $this->preview[] = [
                        'row'         => $absoluteRow,
                        'name'        => $name,
                        'description' => $description,
                        'is_active'   => $isActive,
                        'permissions' => $this->permissionNamesFromIds($permIds),
                        'action'      => 'created',
                    ];
                }
            }

            if ($this->dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
                // Spatie cache — limpia despues del commit para que los nuevos
                // role-permission assignments sean visibles inmediatamente.
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function summary(): array
    {
        return [
            'created'      => $this->created,
            'updated'      => $this->updated,
            'skipped'      => $this->skipped,
            'error_count'  => count($this->errors),
            'total_rows'   => $this->created + $this->updated + $this->skipped + count($this->errors),
            'errors'       => array_slice($this->errors, 0, 50),
            'preview'      => array_slice($this->preview, 0, 100),
            'dry_run'      => $this->dryRun,
        ];
    }

    protected function normalizeName(mixed $value): ?string
    {
        if ($value === null) return null;
        $name = trim((string) $value);
        return $name === '' ? null : $name;
    }

    protected function normalizeDescription(mixed $value): ?string
    {
        if ($value === null) return null;
        $desc = trim((string) $value);
        return $desc === '' ? null : $desc;
    }

    /** Lowercase + strip accents (iconv) — mismo pattern que el DB-level layer 2. */
    protected function normalizeKey(string $name): string
    {
        $lower    = mb_strtolower(trim($name));
        $stripped = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return $stripped !== false ? $stripped : $lower;
    }

    /**
     * Lookup case + accent insensitive (Postgres unaccent / fallback LOWER),
     * scoped al tenant del usuario. Se compara solo dentro del mismo tenant
     * porque el unique de roles es (name, guard_name, tenant_id).
     */
    protected function findExistingByNameInsensitive(string $name): ?Role
    {
        $isPgsql = DB::getDriverName() === 'pgsql';
        $query   = Role::query()->where('guard_name', 'web');

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        } else {
            $query->whereNull('tenant_id');
        }

        if ($isPgsql) {
            $query->whereRaw('unaccent(LOWER(roles.name)) = unaccent(LOWER(?))', [$name]);
        } else {
            $query->whereRaw('LOWER(roles.name) = LOWER(?)', [$name]);
        }

        return $query->first();
    }

    /**
     * Parsea la lista separada por comas y devuelve [ids_validos, nombres_desconocidos].
     * Nombres con espacios extra se trimean. Vacios se ignoran.
     */
    protected function resolvePermissions(mixed $raw): array
    {
        if ($raw === null) return [[], []];

        $raw = trim((string) $raw);
        if ($raw === '') return [[], []];

        $parts = array_filter(array_map('trim', explode(',', $raw)), fn ($p) => $p !== '');
        $ids   = [];
        $unknown = [];

        foreach ($parts as $name) {
            if (isset($this->permissionCache[$name])) {
                $ids[] = $this->permissionCache[$name];
            } else {
                $unknown[] = $name;
            }
        }

        return [array_values(array_unique($ids)), array_values(array_unique($unknown))];
    }

    /** Revierte ids → nombres usando el cache. Para mostrar en preview UI. */
    protected function permissionNamesFromIds(array $ids): array
    {
        if (empty($ids)) return [];
        $idsFlipped = array_flip($ids);
        $names = [];
        foreach ($this->permissionCache as $name => $id) {
            if (isset($idsFlipped[$id])) $names[] = $name;
        }
        return $names;
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
