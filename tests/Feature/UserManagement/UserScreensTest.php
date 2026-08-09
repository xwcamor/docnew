<?php

namespace Tests\Feature\UserManagement;

use App\Models\Role;
use App\Models\User;

/**
 * Que las pantallas del modulo Usuarios ABRAN. Es la prueba barata que
 * encuentra las paginas que revientan por leer una columna que no existe o
 * por una ruta mal armada — el fallo que en Formatos paso desapercibido.
 */
class UserScreensTest extends UserTestCase
{
    public function test_las_pantallas_de_listado_abren_para_el_super(): void
    {
        $this->actingAsSuperAdmin();
        User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        foreach (['index', 'create', 'trash', 'edit_all'] as $p) {
            $this->get(route("user_management.users.{$p}"))
                ->assertOk("La pantalla users.{$p} no abre");
        }
    }

    public function test_las_pantallas_de_una_fila_abren(): void
    {
        $this->actingAsTenantAdmin(1);
        $target = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        foreach (['show', 'edit', 'delete'] as $p) {
            $this->get(route("user_management.users.{$p}", $target->slug))
                ->assertOk("La pantalla users.{$p} no abre");
        }
    }

    public function test_el_alta_guarda_la_fila_en_la_base(): void
    {
        $this->actingAsTenantAdmin(1);
        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');

        $this->post(route('user_management.users.store'), [
            'name'       => 'Marta Quispe',
            'email'      => 'marta@example.com',
            'password'   => 'Password123!',
            'country_id' => 1,
            'locale_id'  => 1,
            'role_id'    => $roleId,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email'      => 'marta@example.com',
            'name'       => 'Marta Quispe',
            'tenant_id'  => 1,
        ]);
    }

    public function test_la_edicion_guarda_el_cambio_en_la_base(): void
    {
        $this->actingAsTenantAdmin(1);
        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');
        $target = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'email' => 'antes@example.com', 'name' => 'Antes',
        ]);

        $this->put(route('user_management.users.update', $target->slug), [
            'name'       => 'Despues',
            'email'      => 'despues@example.com',
            'country_id' => 1,
            'locale_id'  => 1,
            'role_id'    => $roleId,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'id'    => $target->id,
            'name'  => 'Despues',
            'email' => 'despues@example.com',
        ]);
    }

    /**
     * El correo del que ya se borro sigue ocupando el indice UNIQUE de la
     * tabla. Si la validacion solo mira las filas vivas, el alta pasa la
     * validacion y revienta contra la base: 23505 en la cara del usuario.
     */
    public function test_reusar_el_correo_de_un_usuario_borrado_avisa_en_el_campo(): void
    {
        $this->actingAsTenantAdmin(1);
        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');

        $viejo = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'email' => 'repetido@example.com',
        ]);
        $viejo->delete();

        $this->from(route('user_management.users.create'))
            ->post(route('user_management.users.store'), [
                'name'       => 'Otro',
                'email'      => 'repetido@example.com',
                'password'   => 'Password123!',
                'country_id' => 1,
                'locale_id'  => 1,
                'role_id'    => $roleId,
            ])
            ->assertSessionHasErrors('email');
    }

    /**
     * Mismo indice UNIQUE, otro camino: dos workspaces distintos con el mismo
     * correo. La validacion por tenant lo deja pasar y la base lo rechaza.
     */
    public function test_el_mismo_correo_en_otro_workspace_avisa_en_el_campo(): void
    {
        $this->actingAsSuperAdmin();
        $roleId = Role::withoutGlobalScopes()->where('name', 'admin')->value('id');

        User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'email' => 'compartido@example.com',
        ]);

        $this->from(route('user_management.users.create'))
            ->post(route('user_management.users.store'), [
                'name'       => 'Del otro workspace',
                'email'      => 'compartido@example.com',
                'password'   => 'Password123!',
                'country_id' => 1,
                'locale_id'  => 1,
                'tenant_id'  => 2,
                'role_id'    => $roleId,
            ])
            ->assertSessionHasErrors('email');
    }

    /**
     * Los 26 de la migración llevan usuarioN@pendiente.local y no pueden
     * entrar. El listado tiene que contarlos y marcarlos, no dejarlos pasar
     * como un usuario más entre diez filas.
     */
    public function test_el_listado_cuenta_y_marca_los_correos_provisionales(): void
    {
        $this->actingAsTenantAdmin(1);

        User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'name' => 'Migrado', 'email' => 'usuario7@pendiente.local',
        ]);
        User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'name' => 'Normal', 'email' => 'normal@example.com',
        ]);

        $props = $this->get(route('user_management.users.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(1, $props['pendingEmailCount']);

        $rows = collect($props['users']['data'])->keyBy('email');
        $this->assertTrue($rows['usuario7@pendiente.local']['email_pending']);
        $this->assertFalse($rows['normal@example.com']['email_pending']);
    }

    public function test_el_filtro_de_un_toque_deja_solo_los_provisionales(): void
    {
        $this->actingAsTenantAdmin(1);

        User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1, 'email' => 'usuario3@pendiente.local']);
        User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1, 'email' => 'real@example.com']);

        $props = $this->get(route('user_management.users.index', ['pending_email' => 1]))
            ->assertOk()
            ->viewData('page')['props'];

        $emails = collect($props['users']['data'])->pluck('email')->all();
        $this->assertSame(['usuario3@pendiente.local'], $emails);
        $this->assertTrue($props['filters']['pending_email']);
    }

    public function test_la_ficha_avisa_de_que_el_correo_es_provisional(): void
    {
        $this->actingAsTenantAdmin(1);
        $migrado = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
            'email' => 'usuario12@pendiente.local',
        ]);

        $props = $this->get(route('user_management.users.show', $migrado->slug))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertTrue($props['user']['email_pending']);
    }
}
