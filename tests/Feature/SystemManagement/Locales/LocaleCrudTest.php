<?php

namespace Tests\Feature\SystemManagement\Locales;

use App\Models\Locale;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class LocaleCrudTest extends LocaleTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        Locale::factory()->count(15)->create();

        $response = $this->get(route('system_management.locales.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.locales.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_locale(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
            'name' => 'Oceanía',
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('locales', [
            'name'       => 'Oceanía',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        // Body vacío: el helper validLocaleData no aplica porque el test verifica que name es requerido.
        $response = $this->post(route('system_management.locales.store'), []);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Locale::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
            'name' => str_repeat('a', 256),
        ]));

        $response->assertSessionHasErrors('name');
    }

    public function test_show_renders_locale_data(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.locales.show', $locale->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->create();

        $response = $this->get(route('system_management.locales.edit', $locale->slug));
        $response->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->named('Antiguo')->create();

        $response = $this->put(route('system_management.locales.update', $locale->slug), $this->validLocaleData(['name' => 'Nuevo', 'is_active' => false]));

        $response->assertRedirect();
        $locale->refresh();
        $this->assertSame('Nuevo', $locale->name);
        $this->assertFalse($locale->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->create();

        $url = route('system_management.locales.show', $locale->slug);
        $this->assertStringContainsString($locale->slug, $url);
        $this->assertStringNotContainsString('/' . $locale->id, $url);
    }
}
