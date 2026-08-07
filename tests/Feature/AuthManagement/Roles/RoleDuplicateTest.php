<?php

namespace Tests\Feature\AuthManagement\Roles;

use App\Models\Role;
use Spatie\Permission\Models\Permission;

/**
 * Duplicate del modulo Roles. Verifica:
 *   - El clon usa el sufijo configurado en lang/global.duplicate_suffix
 *     (es: "copia", en: "copy") — el test es agnostico al locale activo.
 *   - Los permisos asignados al rol original se copian al clon.
 */
class RoleDuplicateTest extends RoleTestCase
{
    public function test_duplicate_clones_with_copia_suffix(): void
    {
        $admin = $this->actingAsTenantAdmin(1);

        $role = Role::create([
            'name'        => 'Original',
            'description' => 'Para duplicar.',
            'guard_name'  => 'web',
            'tenant_id'   => $admin->tenant_id,
        ]);

        $response = $this->post(route('user_management.roles.duplicate', $role->slug));
        $response->assertRedirect();

        $suffix       = __('global.duplicate_suffix');
        $expectedName = 'Original (' . $suffix . ')';

        $this->assertDatabaseHas('roles', [
            'name'      => $expectedName,
            'tenant_id' => $admin->tenant_id,
        ]);
    }

    public function test_duplicate_copies_permissions(): void
    {
        $admin = $this->actingAsTenantAdmin(1);

        $perm1 = Permission::firstOrCreate(['name' => 'users.view', 'guard_name' => 'web']);
        $perm2 = Permission::firstOrCreate(['name' => 'users.edit', 'guard_name' => 'web']);

        $role = Role::create([
            'name'        => 'Con permisos',
            'description' => 'Tiene permisos asignados.',
            'guard_name'  => 'web',
            'tenant_id'   => $admin->tenant_id,
        ]);
        $role->syncPermissions([$perm1, $perm2]);

        $response = $this->post(route('user_management.roles.duplicate', $role->slug));
        $response->assertRedirect();

        $suffix       = __('global.duplicate_suffix');
        $expectedName = 'Con permisos (' . $suffix . ')';

        $clone = Role::where('name', $expectedName)
            ->where('tenant_id', $admin->tenant_id)
            ->first();

        $this->assertNotNull($clone, 'El clon debe existir.');
        $clonePermNames = $clone->permissions->pluck('name')->all();
        $this->assertContains('users.view', $clonePermNames);
        $this->assertContains('users.edit', $clonePermNames);
    }
}
