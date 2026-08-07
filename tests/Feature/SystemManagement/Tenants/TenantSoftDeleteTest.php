<?php

namespace Tests\Feature\SystemManagement\Tenants;

use App\Models\Tenant;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class TenantSoftDeleteTest extends TenantTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.tenants.deleteSave', $tenant->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $tenant->refresh();
        $this->assertNotNull($tenant->deleted_at);
        $this->assertSame('Migración de datos', $tenant->deleted_description);
        $this->assertSame($admin->id, $tenant->deleted_by);
        $this->assertFalse($tenant->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $response = $this->delete(
            route('system_management.tenants.deleteSave', $tenant->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($tenant->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $c = Tenant::factory()->create();

        $response = $this->post(
            route('system_management.tenants.bulk_delete'),
            [
                'ids'                 => [$a->id, $b->id, $c->id],
                'deleted_description' => 'Limpieza de datos obsoletos',
            ],
        );

        $response->assertRedirect();
        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
        $this->assertNotNull($c->fresh()->deleted_at);
    }

    public function test_bulk_delete_rejects_too_short_reason(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $response = $this->post(
            route('system_management.tenants.bulk_delete'),
            ['ids' => [$tenant->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($tenant->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->trashed()->create();

        $response = $this->post(route('system_management.tenants.restore', $tenant->slug));

        $response->assertRedirect();
        $tenant->refresh();
        $this->assertNull($tenant->deleted_at);
        $this->assertNull($tenant->deleted_description);
        $this->assertNull($tenant->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->trashed()->create();

        $response = $this->post(route('system_management.tenants.restore', $tenant->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($tenant->fresh()->deleted_at, 'Tenant must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.tenants.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
