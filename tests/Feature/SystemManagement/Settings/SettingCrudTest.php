<?php

namespace Tests\Feature\SystemManagement\Settings;

use App\Models\Setting;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class SettingCrudTest extends SettingTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        Setting::factory()->count(15)->create();

        $response = $this->get(route('system_management.settings.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.settings.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_setting(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.settings.store'), $this->validSettingData([
            'name' => 'Oceanía',
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('settings', [
            'name'       => 'Oceanía',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        // Body vacío para verificar required.
        $response = $this->post(route('system_management.settings.store'), []);

        $response->assertSessionHasErrors(['name', 'key', 'type']);
        $this->assertSame(0, Setting::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.settings.store'), $this->validSettingData([
            'name' => str_repeat('a', 256),
        ]));

        $response->assertSessionHasErrors('name');
    }

    public function test_show_renders_setting_data(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.settings.show', $setting->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->create();

        $response = $this->get(route('system_management.settings.edit', $setting->slug));
        $response->assertOk();
    }

    /**
     * El form Vue requiere que edit() entregue todos los campos del modelo.
     * Si falta alguno, useForm los inicializa vacíos y al guardar la validacion
     * falla (key/type required) sin que el user vea el error porque key esta
     * disabled en el HTML.
     */
    public function test_edit_payload_contains_all_form_fields(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->create([
            'key'         => 'test.example_key',
            'name'        => 'Test setting',
            'type'        => 'string',
            'value'       => 'hola',
            'group'       => 'test',
            'description' => 'desc',
            'is_secret'   => false,
            'is_active'   => true,
        ]);

        $response = $this->get(route('system_management.settings.edit', $setting->slug));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Settings/Form')
            ->has('setting.key')
            ->has('setting.name')
            ->has('setting.type')
            ->has('setting.value')
            ->has('setting.group')
            ->has('setting.description')
            ->has('setting.is_secret')
            ->has('setting.is_active')
            ->where('setting.key',   'test.example_key')
            ->where('setting.value', 'hola')
            ->where('setting.type',  'string')
        );
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->named('Antiguo')->create();

        $response = $this->put(route('system_management.settings.update', $setting->slug), $this->validSettingData(['name' => 'Nuevo', 'is_active' => false]));

        $response->assertRedirect();
        $setting->refresh();
        $this->assertSame('Nuevo', $setting->name);
        $this->assertFalse($setting->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $setting = Setting::factory()->create();

        $url = route('system_management.settings.show', $setting->slug);
        $this->assertStringContainsString($setting->slug, $url);
        $this->assertStringNotContainsString('/' . $setting->id, $url);
    }
}
