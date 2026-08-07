<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Models\Region;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class RegionSoftDeleteTest extends RegionTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $region = Region::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.regions.deleteSave', $region->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $region->refresh();
        $this->assertNotNull($region->deleted_at);
        $this->assertSame('Migración de datos', $region->deleted_description);
        $this->assertSame($admin->id, $region->deleted_by);
        $this->assertFalse($region->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        $response = $this->delete(
            route('system_management.regions.deleteSave', $region->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($region->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = Region::factory()->create();
        $b = Region::factory()->create();
        $c = Region::factory()->create();

        $response = $this->post(
            route('system_management.regions.bulk_delete'),
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
        $region = Region::factory()->create();

        $response = $this->post(
            route('system_management.regions.bulk_delete'),
            ['ids' => [$region->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($region->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->trashed()->create();

        $response = $this->post(route('system_management.regions.restore', $region->slug));

        $response->assertRedirect();
        $region->refresh();
        $this->assertNull($region->deleted_at);
        $this->assertNull($region->deleted_description);
        $this->assertNull($region->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $region = Region::factory()->trashed()->create();

        $response = $this->post(route('system_management.regions.restore', $region->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($region->fresh()->deleted_at, 'Region must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.regions.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
