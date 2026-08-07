<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\Country;

/**
 * Locks down the soft-delete + restore + bulk-delete behavior.
 *
 * Reasons matter: every delete (single or bulk) requires a non-trivial reason
 * stored in `deleted_description`. Restore is super-only.
 */
class CountrySoftDeleteTest extends CountryTestCase
{
    public function test_delete_soft_deletes_with_reason_and_user(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $country = Country::factory()->named('Para borrar')->create();

        $response = $this->delete(
            route('system_management.countries.deleteSave', $country->slug),
            ['deleted_description' => 'Migración de datos'],
        );

        $response->assertRedirect();
        $country->refresh();
        $this->assertNotNull($country->deleted_at);
        $this->assertSame('Migración de datos', $country->deleted_description);
        $this->assertSame($admin->id, $country->deleted_by);
        $this->assertFalse($country->is_active, 'Soft-delete should also flip is_active to false.');
    }

    public function test_delete_requires_a_reason(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->create();

        $response = $this->delete(
            route('system_management.countries.deleteSave', $country->slug),
            ['deleted_description' => ''],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($country->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_reason_and_marks_all(): void
    {
        $this->actingAsSuperAdmin();
        $a = Country::factory()->create();
        $b = Country::factory()->create();
        $c = Country::factory()->create();

        $response = $this->post(
            route('system_management.countries.bulk_delete'),
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
        $country = Country::factory()->create();

        $response = $this->post(
            route('system_management.countries.bulk_delete'),
            ['ids' => [$country->id], 'deleted_description' => 'X'],
        );

        $response->assertSessionHasErrors('deleted_description');
        $this->assertNull($country->fresh()->deleted_at);
    }

    public function test_restore_clears_deletion_metadata(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->trashed()->create();

        $response = $this->post(route('system_management.countries.restore', $country->slug));

        $response->assertRedirect();
        $country->refresh();
        $this->assertNull($country->deleted_at);
        $this->assertNull($country->deleted_description);
        $this->assertNull($country->deleted_by);
    }

    public function test_admin_cannot_restore(): void
    {
        $this->actingAsAdmin();
        $country = Country::factory()->trashed()->create();

        $response = $this->post(route('system_management.countries.restore', $country->slug));

        // Custom exception handler turns 403 into a redirect+flash. The
        // important thing is the side-effect didn't happen.
        $response->assertRedirect();
        $this->assertNotNull($country->fresh()->deleted_at, 'Country must remain soft-deleted.');
    }

    public function test_trash_index_is_super_only(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('system_management.countries.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
