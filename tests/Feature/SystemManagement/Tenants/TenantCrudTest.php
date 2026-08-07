<?php

namespace Tests\Feature\SystemManagement\Tenants;

use App\Models\Tenant;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class TenantCrudTest extends TenantTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        Tenant::factory()->count(15)->create();

        $response = $this->get(route('system_management.tenants.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.tenants.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_tenant(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.tenants.store'), $this->validTenantData([
            'name' => 'Oceanía',
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('tenants', [
            'name'       => 'Oceanía',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        // Body vacío para verificar que name es required (no usar validTenantData).
        $response = $this->post(route('system_management.tenants.store'), []);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Tenant::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.tenants.store'), $this->validTenantData([
            'name' => str_repeat('a', 256),
        ]));

        $response->assertSessionHasErrors('name');
    }

    public function test_show_renders_tenant_data(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.tenants.show', $tenant->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $response = $this->get(route('system_management.tenants.edit', $tenant->slug));
        $response->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->named('Antiguo')->create();

        $response = $this->put(route('system_management.tenants.update', $tenant->slug), $this->validTenantData(['name' => 'Nuevo', 'is_active' => false]));

        $response->assertRedirect();
        $tenant->refresh();
        $this->assertSame('Nuevo', $tenant->name);
        $this->assertFalse($tenant->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $tenant = Tenant::factory()->create();

        $url = route('system_management.tenants.show', $tenant->slug);
        $this->assertStringContainsString($tenant->slug, $url);
        $this->assertStringNotContainsString('/' . $tenant->id, $url);
    }
}
