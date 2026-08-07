<?php

namespace Tests\Feature\SystemManagement\Locales;

use App\Models\Locale;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class LocaleSoftDeleteTest extends LocaleTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $locale = Locale::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.locales.deleteSave', $locale->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $locale->refresh();
        $this->assertNotNull($locale->deleted_at);
        $this->assertSame('Migración de datos', $locale->deleted_description);
        $this->assertSame($admin->id, $locale->deleted_by);
        $this->assertFalse($locale->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->create();

        $response = $this->delete(
            route('system_management.locales.deleteSave', $locale->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($locale->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = Locale::factory()->create();
        $b = Locale::factory()->create();
        $c = Locale::factory()->create();

        $response = $this->post(
            route('system_management.locales.bulk_delete'),
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
        $locale = Locale::factory()->create();

        $response = $this->post(
            route('system_management.locales.bulk_delete'),
            ['ids' => [$locale->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($locale->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->trashed()->create();

        $response = $this->post(route('system_management.locales.restore', $locale->slug));

        $response->assertRedirect();
        $locale->refresh();
        $this->assertNull($locale->deleted_at);
        $this->assertNull($locale->deleted_description);
        $this->assertNull($locale->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $locale = Locale::factory()->trashed()->create();

        $response = $this->post(route('system_management.locales.restore', $locale->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($locale->fresh()->deleted_at, 'Locale must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.locales.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
