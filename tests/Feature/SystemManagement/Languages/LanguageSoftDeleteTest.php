<?php

namespace Tests\Feature\SystemManagement\Languages;

use App\Models\Language;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class LanguageSoftDeleteTest extends LanguageTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $language = Language::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.languages.deleteSave', $language->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $language->refresh();
        $this->assertNotNull($language->deleted_at);
        $this->assertSame('Migración de datos', $language->deleted_description);
        $this->assertSame($admin->id, $language->deleted_by);
        $this->assertFalse($language->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $language = Language::factory()->create();

        $response = $this->delete(
            route('system_management.languages.deleteSave', $language->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($language->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = Language::factory()->create();
        $b = Language::factory()->create();
        $c = Language::factory()->create();

        $response = $this->post(
            route('system_management.languages.bulk_delete'),
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
        $language = Language::factory()->create();

        $response = $this->post(
            route('system_management.languages.bulk_delete'),
            ['ids' => [$language->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($language->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $language = Language::factory()->trashed()->create();

        $response = $this->post(route('system_management.languages.restore', $language->slug));

        $response->assertRedirect();
        $language->refresh();
        $this->assertNull($language->deleted_at);
        $this->assertNull($language->deleted_description);
        $this->assertNull($language->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $language = Language::factory()->trashed()->create();

        $response = $this->post(route('system_management.languages.restore', $language->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($language->fresh()->deleted_at, 'Region must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.languages.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
