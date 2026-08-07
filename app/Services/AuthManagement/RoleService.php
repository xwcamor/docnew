<?php

namespace App\Services\AuthManagement;

use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * RoleService — operaciones de negocio para Spatie Role custom.
 *
 * NO usa Jobs async (la cardinalidad de roles es baja por diseño, ~30 por
 * tenant). Si en el futuro un tenant llega a tener cientos de roles custom
 * y bulk_delete excede 200, agregar BulkRolesActionJob siguiendo el patrón
 * Regions/BulkRegionsActionJob.
 */
class RoleService
{
    public function create(array $data): Role
    {
        $data['created_by'] = auth()->id();
        return Role::create($data);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update($data);
        return $role;
    }

    public function delete(Role $role, string $reason): void
    {
        // Bloquear delete si tiene users asignados — UX safety.
        abort_if($role->users()->count() > 0, 409, __('roles.delete_blocked_users'));

        $role->deleted_description = $reason;
        $role->deleted_by          = auth()->id();
        $role->is_active           = false;
        $role->saveQuietly();
        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function restore(Role $role): Role
    {
        $role->deleted_description = null;
        $role->deleted_by          = null;
        $role->restore();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        return $role;
    }

    /**
     * Hard delete. Audit ANTES del delete + transacción para atomicidad.
     */
    public function forceDelete(Role $role, string $reason): void
    {
        DB::transaction(function () use ($role, $reason) {
            $locked = Role::onlyTrashed()->where('id', $role->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new \RuntimeException("Role {$role->id} no longer available for force-delete");
            }

            AuditLog::create([
                'user_id'        => auth()->id(),
                'auditable_type' => Role::class,
                'auditable_id'   => $locked->id,
                'event'          => 'force_deleted',
                'old_values'     => [
                    'name'        => $locked->name,
                    'description' => $locked->description,
                    'tenant_id'   => $locked->tenant_id,
                    'slug'        => $locked->slug,
                ],
                'new_values'     => null,
                'url'            => request()?->fullUrl(),
                'ip_address'     => request()?->ip(),
                'user_agent'     => substr((string) request()?->userAgent(), 0, 500),
                'note'           => $reason,
                'module'         => 'roles',
                'created_at'     => now(),
            ]);

            // Cascade manual a pivots Spatie.
            DB::table('role_has_permissions')->where('role_id', $locked->id)->delete();
            DB::table('model_has_roles')->where('role_id', $locked->id)->delete();
            $locked->forceDelete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function duplicate(Role $role): ?Role
    {
        $base    = $role->name . ' (' . __('global.duplicate_suffix') . ')';
        $isPgsql = DB::getDriverName() === 'pgsql';

        return DB::transaction(function () use ($role, $base, $isPgsql) {
            $candidate = $base;
            $i = 2;

            while (true) {
                $exists = Role::query()
                    ->when($isPgsql,
                        fn ($q) => $q->whereRaw('unaccent(LOWER(name)) = unaccent(LOWER(?))', [$candidate]),
                        fn ($q) => $q->whereRaw('LOWER(name) = LOWER(?)', [$candidate]),
                    )
                    ->where('tenant_id', $role->tenant_id)
                    ->lockForUpdate()
                    ->exists();

                if (!$exists) break;
                $candidate = $base . ' ' . $i;
                $i++;
                if ($i > 100) return null;
            }

            $copy = $this->create([
                'name'        => $candidate,
                'description' => $role->description,
                'guard_name'  => $role->guard_name,
                'tenant_id'   => $role->tenant_id,
                'is_active'   => $role->is_active,
            ]);

            // Copiar permissions del original.
            $copy->syncPermissions($role->permissions);

            return $copy;
        });
    }

    // ─── Bulk ops (sync, sin async porque cardinalidad baja) ─────────────

    public function bulkDelete(array $ids, string $reason): array
    {
        return DB::transaction(function () use ($ids, $reason) {
            $roles = Role::whereIn('id', $ids)->get();
            $deleted = [];
            $skipped = [];

            foreach ($roles as $role) {
                if ($role->users()->count() > 0) {
                    $skipped[] = $role->id;
                    continue;
                }
                $this->delete($role, $reason);
                $deleted[] = $role->id;
            }

            return ['deleted' => $deleted, 'skipped_with_users' => $skipped];
        });
    }

    public function bulkSetActive(array $ids, bool $isActive): int
    {
        return DB::transaction(function () use ($ids, $isActive) {
            $changed = 0;
            $roles = Role::whereIn('id', $ids)->get();
            foreach ($roles as $role) {
                if ((bool) $role->is_active === $isActive) continue;
                $role->update(['is_active' => $isActive]);
                $changed++;
            }
            return $changed;
        });
    }

    public function bulkRestore(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $roles = Role::onlyTrashed()->whereIn('id', $ids)->get();
            foreach ($roles as $role) {
                $this->restore($role);
            }
            return $roles->count();
        });
    }
}
