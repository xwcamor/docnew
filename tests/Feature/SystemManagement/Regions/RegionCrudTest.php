<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Models\Region;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class RegionCrudTest extends RegionTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        Region::factory()->count(15)->create();

        $response = $this->get(route('system_management.regions.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.regions.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_region(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => 'Oceanía',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('regions', [
            'name'       => 'Oceanía',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.regions.store'), []);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Region::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => str_repeat('a', 256),
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_show_renders_region_data(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.regions.show', $region->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        $response = $this->get(route('system_management.regions.edit', $region->slug));
        $response->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->named('Antiguo')->create();

        $response = $this->put(
            route('system_management.regions.update', $region->slug),
            ['name' => 'Nuevo', 'is_active' => false],
        );

        $response->assertRedirect();
        $region->refresh();
        $this->assertSame('Nuevo', $region->name);
        $this->assertFalse($region->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        $url = route('system_management.regions.show', $region->slug);
        $this->assertStringContainsString($region->slug, $url);
        $this->assertStringNotContainsString('/' . $region->id, $url);
    }
}
