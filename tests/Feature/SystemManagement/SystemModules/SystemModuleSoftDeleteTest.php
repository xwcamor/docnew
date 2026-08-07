<?php

namespace Tests\Feature\SystemManagement\SystemModules;

use App\Models\SystemModule;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class SystemModuleSoftDeleteTest extends SystemModuleTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.system_modules.deleteSave', $system_module->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $system_module->refresh();
        $this->assertNotNull($system_module->deleted_at);
        $this->assertSame('Migración de datos', $system_module->deleted_description);
        $this->assertSame($admin->id, $system_module->deleted_by);
        $this->assertFalse($system_module->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->create();

        $response = $this->delete(
            route('system_management.system_modules.deleteSave', $system_module->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($system_module->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = SystemModule::factory()->create();
        $b = SystemModule::factory()->create();
        $c = SystemModule::factory()->create();

        $response = $this->post(
            route('system_management.system_modules.bulk_delete'),
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
        $system_module = SystemModule::factory()->create();

        $response = $this->post(
            route('system_management.system_modules.bulk_delete'),
            ['ids' => [$system_module->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($system_module->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->trashed()->create();

        $response = $this->post(route('system_management.system_modules.restore', $system_module->slug));

        $response->assertRedirect();
        $system_module->refresh();
        $this->assertNull($system_module->deleted_at);
        $this->assertNull($system_module->deleted_description);
        $this->assertNull($system_module->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $system_module = SystemModule::factory()->trashed()->create();

        $response = $this->post(route('system_management.system_modules.restore', $system_module->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($system_module->fresh()->deleted_at, 'SystemModule must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.system_modules.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
