<?php

namespace Tests\Feature\AuthManagement\Roles;

use App\Models\Role;
use App\Models\User;
use Spatie\Permission\Models\Permission;

/**
 * CRUD principal del modulo Roles. Smoke tests del happy path + las dos
 * reglas de negocio criticas: soft-delete con motivo y bloqueo cuando el
 * rol tiene usuarios asignados (409).
 *
 * Nota: las rutas viven bajo el prefijo `user_management.roles.*` (no
 * `auth_management.*`) — el controller esta en App\Http\Controllers\
 * AuthManagement por compatibilidad historica, pero las URLs estan
 * agrupadas en user_management.php.
 */
class RoleCrudTest extends RoleTestCase
{
    public function test_index_renders_for_admin(): void
    {
        $this->actingAsTenantAdmin(1);

        $response = $this->get(route('user_management.roles.index'));
        $response->assertOk();
    }

    public function test_admin_can_create_role(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $perm  = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']);

        $response = $this->post(route('user_management.roles.store'), [
            'name'        => 'Soporte Custom',
            'description' => 'Atiende tickets del workspace.',
            'permissions' => [$perm->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', [
            'name'       => 'Soporte Custom',
            'guard_name' => 'web',
            'tenant_id'  => $admin->tenant_id,
        ]);
    }

    public function test_role_requires_at_least_one_permission(): void
    {
        $this->actingAsTenantAdmin(1);

        $response = $this->from(route('user_management.roles.create'))
            ->post(route('user_management.roles.store'), [
                'name'        => 'Sin Permisos',
                'description' => 'No tiene permisos.',
                'permissions' => [],
            ]);

        $response->assertSessionHasErrors('permissions');
        $this->assertDatabaseMissing('roles', ['name' => 'Sin Permisos']);
    }

    public function test_admin_can_update_role(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $role  = Role::create([
            'name'        => 'Antiguo Perfil',
            'description' => 'Descripcion vieja.',
            'guard_name'  => 'web',
            'tenant_id'   => $admin->tenant_id,
        ]);

        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'customers.view', 'guard_name' => 'web']);
        $response = $this->put(route('user_management.roles.update', $role->slug), [
            'name'        => 'Nuevo Perfil',
            'description' => 'Descripcion actualizada.',
            'permissions' => [$perm->id],
        ]);

        $response->assertRedirect();
        $role->refresh();
        $this->assertSame('Nuevo Perfil', $role->name);
        $this->assertSame('Descripcion actualizada.', $role->description);
    }

    /**
     * El form de edición envía el PUT a roles/{role} y la ruta liga por slug
     * (getRouteKeyName). Si edit() no expone el slug, props.role.slug queda
     * undefined, la URL del PUT se arma sin parámetro y el guardado nunca
     * llega — el síntoma reportado ("no guarda los cambios").
     */
    public function test_edit_exposes_slug_for_update_routing(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $role  = Role::create([
            'name'        => 'Perfil Editable',
            'description' => 'Para editar.',
            'guard_name'  => 'web',
            'tenant_id'   => $admin->tenant_id,
        ]);

        $this->get(route('user_management.roles.edit', $role->slug))
            ->assertInertia(fn ($p) => $p
                ->component('Roles/Form')
                ->where('role.slug', $role->slug)
                ->where('role.id', $role->id));
    }

    public function test_soft_delete_with_reason(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $role  = Role::create([
            'name'        => 'Para borrar',
            'description' => 'Eliminable.',
            'guard_name'  => 'web',
            'tenant_id'   => $admin->tenant_id,
        ]);

        $response = $this->delete(route('user_management.roles.deleteSave', $role->slug), [
            'deleted_description' => 'Perfil obsoleto tras reorganizacion.',
        ]);

        $response->assertRedirect();
        $this->assertSoftDeleted('roles', ['id' => $role->id]);
    }

    public function test_delete_blocked_when_role_has_users(): void
    {
        $admin = $this->actingAsTenantAdmin(1);

        $role = Role::create([
            'name'        => 'Con usuarios asignados',
            'description' => 'Tiene gente.',
            'guard_name'  => 'web',
            'tenant_id'   => $admin->tenant_id,
        ]);

        // Asignamos el rol a otro user del mismo tenant para que el service
        // detecte usuarios asignados y dispare abort(409).
        $target = User::factory()->create([
            'tenant_id'  => $admin->tenant_id,
            'country_id' => 1,
            'locale_id'  => 1,
        ]);
        $target->assignRole($role);

        // El controller `deleteSave` delega al RoleService::delete() que hace
        // abort(409) si el rol tiene users. bootstrap/app.php intercepta el
        // 403/404 pero NO el 409 — el 409 cae al default handler. Verificamos
        // que el rol NO se borre.
        try {
            $this->delete(route('user_management.roles.deleteSave', $role->slug), [
                'deleted_description' => 'Intento de borrado bloqueado.',
            ]);
        } catch (\Throwable $e) {
            // Aceptamos cualquier excepcion HTTP — la afirmacion clave es la
            // ausencia de side effect en la DB.
        }

        $this->assertDatabaseHas('roles', [
            'id'         => $role->id,
            'deleted_at' => null,
        ]);
    }
}
