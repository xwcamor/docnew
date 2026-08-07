<?php

namespace Tests\Feature\SystemManagement\Settings;

use App\Models\Setting;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class SettingSoftDeleteTest extends SettingTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $setting = Setting::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.settings.deleteSave', $setting->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $setting->refresh();
        $this->assertNotNull($setting->deleted_at);
        $this->assertSame('Migración de datos', $setting->deleted_description);
        $this->assertSame($admin->id, $setting->deleted_by);
        $this->assertFalse($setting->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->create();

        $response = $this->delete(
            route('system_management.settings.deleteSave', $setting->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($setting->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = Setting::factory()->create();
        $b = Setting::factory()->create();
        $c = Setting::factory()->create();

        $response = $this->post(
            route('system_management.settings.bulk_delete'),
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
        $setting = Setting::factory()->create();

        $response = $this->post(
            route('system_management.settings.bulk_delete'),
            ['ids' => [$setting->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($setting->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->trashed()->create();

        $response = $this->post(route('system_management.settings.restore', $setting->slug));

        $response->assertRedirect();
        $setting->refresh();
        $this->assertNull($setting->deleted_at);
        $this->assertNull($setting->deleted_description);
        $this->assertNull($setting->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $setting = Setting::factory()->trashed()->create();

        $response = $this->post(route('system_management.settings.restore', $setting->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($setting->fresh()->deleted_at, 'Setting must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.settings.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
