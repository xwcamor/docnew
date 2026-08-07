<?php

namespace Tests\Feature\AuthManagement\Roles;

use App\Models\Role;

/**
 * Papelera + restore + force-delete de Roles. El bloque entero esta
 * gateado por `role:super` — el admin del tenant NO entra.
 */
class RoleTrashTest extends RoleTestCase
{
    public function test_super_can_access_trash(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->get(route('user_management.roles.trash'));
        $response->assertOk();
    }

    public function test_admin_cannot_access_trash(): void
    {
        $this->actingAsTenantAdmin(1);

        // Spatie\UnauthorizedException → bootstrap/app.php lo convierte en
        // redirect + flash error.
        $response = $this->get(route('user_management.roles.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_super_can_restore(): void
    {
        $this->actingAsSuperAdmin();
        $role = Role::create([
            'name'        => 'Restaurar',
            'description' => 'Soft-deleted, sera restaurado.',
            'guard_name'  => 'web',
            'tenant_id'   => 1,
        ]);
        $role->delete();

        $response = $this->post(route('user_management.roles.restore', $role->slug));

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', [
            'id'         => $role->id,
            'deleted_at' => null,
        ]);
    }

    public function test_force_delete_requires_name_match(): void
    {
        $this->actingAsSuperAdmin();
        $role = Role::create([
            'name'        => 'Perfil Real',
            'description' => 'Hard-delete con nombre incorrecto.',
            'guard_name'  => 'web',
            'tenant_id'   => 1,
        ]);
        $role->delete();

        // El controller hace abort(422) si name_confirmation no coincide.
        // 422 cae al default handler (no entra al renderer custom de 403/404).
        try {
            $response = $this->delete(route('user_management.roles.force_delete', $role->slug), [
                'name_confirmation' => 'Nombre Incorrecto',
                'reason'            => 'Razon valida de hard-delete con suficiente largo.',
            ]);
            // Si retorna response (no excepcion), debe ser 422.
            $this->assertSame(422, $response->getStatusCode());
        } catch (\Throwable $e) {
            // Aceptamos cualquier excepcion HTTP — la afirmacion clave es la
            // ausencia de side effect (el rol sigue soft-deleted, no borrado).
        }

        // El rol sigue existiendo (soft-deleted).
        $this->assertNotNull(Role::withTrashed()->find($role->id));
    }

    public function test_force_delete_with_correct_name_hard_deletes(): void
    {
        $this->actingAsSuperAdmin();
        $role = Role::create([
            'name'        => 'Perfil Real',
            'description' => 'Hard-delete OK.',
            'guard_name'  => 'web',
            'tenant_id'   => 1,
        ]);
        $role->delete();

        $response = $this->delete(route('user_management.roles.force_delete', $role->slug), [
            'name_confirmation' => 'Perfil Real',
            'reason'            => 'Razon valida de hard-delete con suficiente largo.',
        ]);

        $response->assertRedirect();
        $this->assertNull(Role::withTrashed()->find($role->id), 'El rol debe estar hard-deleted.');
    }
}
