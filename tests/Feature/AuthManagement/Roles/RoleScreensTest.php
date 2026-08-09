<?php

namespace Tests\Feature\AuthManagement\Roles;

use App\Models\Role;
use Spatie\Permission\Models\Permission;

/**
 * Que las pantallas del modulo Perfiles ABRAN, y que el alta y la edicion
 * dejen la fila escrita en la base. Es la prueba barata que encuentra las
 * paginas que revientan o las redirecciones que caen en un 404.
 */
class RoleScreensTest extends RoleTestCase
{
    protected function rolePropio(int $tenantId = 1): Role
    {
        return Role::create([
            'name'        => 'Perfil de obra',
            'description' => 'Perfil de prueba.',
            'guard_name'  => 'web',
            'tenant_id'   => $tenantId,
        ]);
    }

    public function test_las_pantallas_de_listado_abren_para_el_super(): void
    {
        $this->actingAsSuperAdmin();
        $this->rolePropio(1);

        foreach (['index', 'create', 'trash', 'edit_all'] as $p) {
            $this->get(route("user_management.roles.{$p}"))
                ->assertOk("La pantalla roles.{$p} no abre");
        }
    }

    public function test_las_pantallas_de_una_fila_abren(): void
    {
        $this->actingAsTenantAdmin(1);
        $role = $this->rolePropio(1);

        foreach (['show', 'edit', 'delete'] as $p) {
            $this->get(route("user_management.roles.{$p}", $role->slug))
                ->assertOk("La pantalla roles.{$p} no abre");
        }
    }

    public function test_el_alta_guarda_la_fila_en_la_base(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $perm  = Permission::firstOrCreate(['name' => 'people.view', 'guard_name' => 'web']);

        $this->post(route('user_management.roles.store'), [
            'name'        => 'Capataz',
            'description' => 'Arma la cuadrilla del dia.',
            'permissions' => [$perm->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roles', [
            'name'        => 'Capataz',
            'description' => 'Arma la cuadrilla del dia.',
            'tenant_id'   => $admin->tenant_id,
        ]);
    }

    public function test_la_edicion_guarda_el_cambio_en_la_base(): void
    {
        $this->actingAsTenantAdmin(1);
        $role = $this->rolePropio(1);
        $perm = Permission::firstOrCreate(['name' => 'people.view', 'guard_name' => 'web']);

        $this->put(route('user_management.roles.update', $role->slug), [
            'name'        => 'Capataz mayor',
            'description' => 'Descripcion nueva.',
            'permissions' => [$perm->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roles', [
            'id'          => $role->id,
            'name'        => 'Capataz mayor',
            'description' => 'Descripcion nueva.',
        ]);
    }

    /**
     * Duplicar deja al usuario en la ficha del clon. La ruta liga por slug,
     * asi que redirigir con el id manda a una pagina que no existe.
     */
    public function test_al_duplicar_la_pagina_de_destino_abre(): void
    {
        $this->actingAsTenantAdmin(1);
        $role = $this->rolePropio(1);

        $response = $this->post(route('user_management.roles.duplicate', $role->slug));
        $response->assertRedirect();

        $this->get($response->headers->get('Location'))
            ->assertOk('El destino de "duplicar" no abre');
    }
}
