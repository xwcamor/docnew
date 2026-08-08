<?php

namespace Tests\Feature\SystemManagement\SystemModules;

use App\Models\SystemModule;

/**
 * Smoke tests for the CRUD happy path. Verifies pages render and inserts/updates
 * actually persist. The harder cases (permissions, dedup, soft-delete) live in
 * sibling test files.
 */
class SystemModuleCrudTest extends SystemModuleTestCase
{
    public function test_index_renders_paginated_list(): void
    {
        $this->actingAsSuperAdmin();
        SystemModule::factory()->count(15)->create();

        $response = $this->get(route('system_management.system_modules.index'));
        $response->assertOk();
    }

    public function test_create_page_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        $response = $this->get(route('system_management.system_modules.create'));
        $response->assertOk();
    }

    public function test_store_persists_a_new_system_module(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.system_modules.store'), $this->validSystemModuleData([
            'name' => 'Oceanía',
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('system_modules', [
            'name'       => 'Oceanía',
            'is_active'  => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_store_rejects_missing_name(): void
    {
        $this->actingAsSuperAdmin();

        // Body vacío para verificar que name es required.
        $response = $this->post(route('system_management.system_modules.store'), []);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, SystemModule::count());
    }

    public function test_store_rejects_name_over_255_chars(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.system_modules.store'), $this->validSystemModuleData([
            'name' => str_repeat('a', 256),
        ]));

        $response->assertSessionHasErrors('name');
    }

    public function test_show_renders_system_module_data(): void
    {
        $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->named('Asia')->create();

        $response = $this->get(route('system_management.system_modules.show', $system_module->slug));
        $response->assertOk();
    }

    public function test_edit_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->create();

        $response = $this->get(route('system_management.system_modules.edit', $system_module->slug));
        $response->assertOk();
    }

    public function test_update_persists_changes(): void
    {
        $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->named('Antiguo')->create();

        $response = $this->put(route('system_management.system_modules.update', $system_module->slug), $this->validSystemModuleData(['name' => 'Nuevo', 'is_active' => false]));

        $response->assertRedirect();
        $system_module->refresh();
        $this->assertSame('Nuevo', $system_module->name);
        $this->assertFalse($system_module->is_active);
    }

    public function test_route_uses_slug_not_id_for_show(): void
    {
        $this->actingAsSuperAdmin();
        $system_module = SystemModule::factory()->create();

        $url = route('system_management.system_modules.show', $system_module->slug);
        // Se compara el ULTIMO SEGMENTO y no la cadena entera. Buscar "/1" dentro
        // de la URL fallaba una de cada sesenta veces sin que nada estuviera
        // roto: el slug es aleatorio y de vez en cuando empieza por el mismo
        // digito que el id.
        $ultimo = basename(parse_url($url, PHP_URL_PATH));
        $this->assertSame($system_module->slug, $ultimo);
        $this->assertNotSame((string) $system_module->id, $ultimo);
    }
}
