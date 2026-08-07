<?php

namespace Tests\Feature\SystemManagement\Languages;

use App\Models\Language;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class LanguageCrudTest extends LanguageTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        Language::factory()->count(15)->create();

        $response = $this->get(route('system_management.languages.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.languages.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_language(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.languages.store'), [
            'name'     => 'Oceanía',
            'iso_code' => 'oc',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('languages', [
            'name'       => 'Oceanía',
            'iso_code'   => 'oc',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.languages.store'), []);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Language::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.languages.store'), [
            'name'     => str_repeat('a', 256),
            'iso_code' => 'aa',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_store_rejects_invalid_iso_code(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.languages.store'), [
            'name'     => 'Test',
            'iso_code' => 'INVALID',
        ]);

        $response->assertSessionHasErrors('iso_code');
    }

    public function test_show_renders_region_data(): void
    {
        $this->actingAsSuperAdmin();
        $language = Language::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.languages.show', $language->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $language = Language::factory()->create();

        $response = $this->get(route('system_management.languages.edit', $language->slug));
        $response->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $language = Language::factory()->named('Antiguo')->create();

        $response = $this->put(
            route('system_management.languages.update', $language->slug),
            ['name' => 'Nuevo', 'iso_code' => $language->iso_code, 'is_active' => false],
        );

        $response->assertRedirect();
        $language->refresh();
        $this->assertSame('Nuevo', $language->name);
        $this->assertFalse($language->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $language = Language::factory()->create();

        $url = route('system_management.languages.show', $language->slug);
        $this->assertStringContainsString($language->slug, $url);
        $this->assertStringNotContainsString('/' . $language->id, $url);
    }
}
