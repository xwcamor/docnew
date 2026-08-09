<?php

namespace Tests\Feature\SystemManagement\SystemModules;

use App\Models\SystemModule;
use App\Observers\SystemModuleObserver;
use Spatie\Permission\Models\Permission;

/**
 * Que las pantallas abran y que dar de alta un módulo no reviente.
 *
 * Este módulo es el que genera los siete permisos de cada uno de los demás: un
 * alta que falla aquí deja al módulo nuevo invisible incluso para el super.
 */
class SystemModuleScreensTest extends SystemModuleTestCase
{
    public function test_all_screens_open(): void
    {
        $this->actingAsSuperAdmin();
        $modulo = SystemModule::create(['name' => 'Andamio']);

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.system_modules.{$pantalla}"))->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.system_modules.{$pantalla}", $modulo->slug))->assertOk();
        }
    }

    /** El observer crea SIETE acciones; la pantalla debe anunciar las mismas. */
    public function test_create_generates_the_seven_canonical_permissions(): void
    {
        $this->actingAsSuperAdmin();

        $this->post(route('system_management.system_modules.store'), ['name' => 'Andamio'])
            ->assertRedirect();

        $modulo = SystemModule::where('permission_key', 'andamios')->first();
        $this->assertNotNull($modulo);

        $creados = Permission::where('name', 'like', 'andamios.%')->pluck('name')->sort()->values()->all();
        $esperados = collect(SystemModuleObserver::CANONICAL_ACTIONS)
            ->map(fn ($a) => "andamios.{$a}")->sort()->values()->all();

        $this->assertSame($esperados, $creados);
        $this->assertCount(7, $creados);
    }

    /** La pantalla de alta debe recibir la MISMA lista de acciones que el observer. */
    public function test_create_screen_announces_the_real_action_list(): void
    {
        $this->actingAsSuperAdmin();

        $this->get(route('system_management.system_modules.create'))
            ->assertInertia(fn ($p) => $p->where('canonicalActions', SystemModuleObserver::CANONICAL_ACTIONS));
    }

    /**
     * `permission_key` tiene UNIQUE de tabla entera (incluye la papelera) y el
     * unique del nombre ignora los eliminados. Reutilizar el nombre de un
     * módulo borrado moría con un 23502/23505 de Postgres en pantalla; ahora
     * el problema se cuenta en su campo.
     */
    public function test_reusing_the_name_of_a_trashed_module_fails_in_the_field(): void
    {
        $this->actingAsSuperAdmin();

        $modulo = SystemModule::create(['name' => 'Andamio']);
        $modulo->delete();

        $this->from(route('system_management.system_modules.create'))
            ->post(route('system_management.system_modules.store'), ['name' => 'Andamio'])
            ->assertRedirect(route('system_management.system_modules.create'))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, SystemModule::where('permission_key', 'andamios')->count());
    }

    /** Restaurado el módulo, el nombre vuelve a estar tomado por el vivo. */
    public function test_after_restoring_the_module_the_name_is_taken_again(): void
    {
        $this->actingAsSuperAdmin();

        $modulo = SystemModule::create(['name' => 'Andamio']);
        $modulo->delete();
        $modulo->restore();

        $this->post(route('system_management.system_modules.store'), ['name' => 'Andamio'])
            ->assertSessionHasErrors('name');
    }

    /** El listado tiene que traer la clave de permiso: es lo que identifica la fila. */
    public function test_index_carries_the_permission_key(): void
    {
        $this->actingAsSuperAdmin();
        SystemModule::create(['name' => 'Andamio']);

        $this->get(route('system_management.system_modules.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('system_modules.data.0.permission_key', 'andamios'));
    }
}
