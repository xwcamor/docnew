<?php

namespace Tests\Feature\AuthManagement\Roles;

use App\Models\Role;

/**
 * Bulk ops del modulo Roles: bulk_delete, bulk_set_active, bulk_restore.
 * Las tres viven bajo `role:super|admin + plan_feature:team_management`,
 * salvo bulk_restore que es super only (idem Customers/Regions).
 */
class RoleBulkTest extends RoleTestCase
{
    public function test_bulk_delete(): void
    {
        $admin = $this->actingAsTenantAdmin(1);

        $a = Role::create(['name' => 'A', 'description' => 'A', 'guard_name' => 'web', 'tenant_id' => $admin->tenant_id]);
        $b = Role::create(['name' => 'B', 'description' => 'B', 'guard_name' => 'web', 'tenant_id' => $admin->tenant_id]);
        $c = Role::create(['name' => 'C', 'description' => 'C', 'guard_name' => 'web', 'tenant_id' => $admin->tenant_id]);

        $response = $this->post(route('user_management.roles.bulk_delete'), [
            'ids'                 => [$a->id, $b->id, $c->id],
            'deleted_description' => 'Limpieza masiva de perfiles obsoletos.',
        ]);

        $response->assertRedirect();
        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
        $this->assertNotNull($c->fresh()->deleted_at);
    }

    public function test_bulk_set_active(): void
    {
        $admin = $this->actingAsTenantAdmin(1);

        $a = Role::create(['name' => 'A', 'description' => 'A', 'guard_name' => 'web', 'tenant_id' => $admin->tenant_id, 'is_active' => true]);
        $b = Role::create(['name' => 'B', 'description' => 'B', 'guard_name' => 'web', 'tenant_id' => $admin->tenant_id, 'is_active' => true]);
        $c = Role::create(['name' => 'C', 'description' => 'C', 'guard_name' => 'web', 'tenant_id' => $admin->tenant_id, 'is_active' => true]);

        $response = $this->post(route('user_management.roles.bulk_set_active'), [
            'ids'       => [$a->id, $b->id, $c->id],
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse((bool) $a->fresh()->is_active);
        $this->assertFalse((bool) $b->fresh()->is_active);
        $this->assertFalse((bool) $c->fresh()->is_active);
    }

    public function test_bulk_restore(): void
    {
        // bulk_restore es super only (BulkRestoreRoleRequest::authorize).
        $this->actingAsSuperAdmin();

        $a = Role::create(['name' => 'A', 'description' => 'A', 'guard_name' => 'web', 'tenant_id' => 1]);
        $b = Role::create(['name' => 'B', 'description' => 'B', 'guard_name' => 'web', 'tenant_id' => 1]);
        $a->delete();
        $b->delete();

        $response = $this->post(route('user_management.roles.bulk_restore'), [
            'ids' => [$a->id, $b->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', ['id' => $a->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('roles', ['id' => $b->id, 'deleted_at' => null]);
    }
}
