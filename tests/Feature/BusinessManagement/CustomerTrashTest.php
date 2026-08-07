<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;

/**
 * Papelera + restore + force-delete de Customers.
 *
 * El bloque entero (trash/restore/force_delete) esta gateado por
 * `role:super`. El admin del tenant NO ve la papelera ni puede
 * restaurar — patron clonado de Regions.
 */
class CustomerTrashTest extends CustomerTestCase
{
    public function test_super_can_access_trash(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->get(route('business_management.customers.trash'));
        $response->assertOk();
    }

    public function test_admin_cannot_access_trash(): void
    {
        $this->actingAsTenantAdmin(1);

        // El middleware role:super dispara UnauthorizedException;
        // bootstrap/app.php lo convierte en redirect+flash error.
        $response = $this->get(route('business_management.customers.trash'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_super_can_restore(): void
    {
        $this->actingAsSuperAdmin();
        $customer = Customer::factory()->create(['tenant_id' => 1]);
        $customer->delete();

        $response = $this->post(route('business_management.customers.restore', $customer->slug));

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
        ]);
    }

    public function test_force_delete_requires_name_match(): void
    {
        $this->actingAsSuperAdmin();
        $customer = Customer::factory()->create(['tenant_id' => 1, 'name' => 'Cliente Real']);
        $customer->delete();

        $response = $this->delete(route('business_management.customers.force_delete', $customer->slug), [
            'name_confirmation' => 'Nombre Incorrecto',
            'reason'            => 'Eliminacion definitiva por cierre de cuenta.',
        ]);

        // El controller devuelve back() con errors si el name no coincide.
        $response->assertSessionHasErrors('name_confirmation');

        // El registro sigue soft-deleted, no hard-deleted.
        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    public function test_force_delete_with_correct_name_hard_deletes(): void
    {
        $this->actingAsSuperAdmin();
        $customer = Customer::factory()->create(['tenant_id' => 1, 'name' => 'Cliente Real']);
        $customer->delete();

        $response = $this->delete(route('business_management.customers.force_delete', $customer->slug), [
            'name_confirmation' => 'Cliente Real',
            'reason'            => 'Eliminacion definitiva por cierre de cuenta.',
        ]);

        $response->assertRedirect();
        $this->assertNull(Customer::withTrashed()->find($customer->id), 'El customer debe estar hard-deleted.');
    }
}
