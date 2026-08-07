<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Usuario RESTRINGIDO a clientes asignados (customer_user) — no crea ni duplica.
 *
 * Un usuario acotado a su cartera (assigned_customer_ids) está scopeado a esos
 * clientes; crear/duplicar uno nuevo lo dejaría huérfano (ni lo vería). El gate
 * NO depende del rol (un admin restringido tampoco crea), solo de tener
 * clientes asignados.
 */
class CustomerRestrictedUserTest extends CustomerTestCase
{
    /** Asigna un cliente al usuario actual → queda restringido. */
    private function restrictTo(int $userId, int $customerId): void
    {
        DB::table('customer_user')->insert([
            'user_id' => $userId, 'customer_id' => $customerId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Re-autenticar con instancia FRESCA: assignedCustomerIds() cachea, y el
        // objeto previo (de actingAs) tenía el cache vacío de antes del insert.
        // En producción no aplica (cada request usa una instancia nueva).
        $this->actingAs(\App\Models\User::withoutGlobalScopes()->find($userId));
    }

    public function test_usuario_restringido_no_puede_crear_cliente(): void
    {
        $admin = $this->actingAsTenantAdmin(1); // tiene customers.create
        $own = Customer::create(['name' => 'Asignado', 'cod' => 'A1', 'country_id' => 2, 'is_active' => true]);
        $this->restrictTo($admin->id, $own->id);

        // El store está bloqueado (authorize del request) aunque tenga el permiso.
        $this->post(route('business_management.customers.store'), [
            'name' => 'Cliente Nuevo', 'cod' => 'N1', 'country_id' => 2,
        ]);

        $this->assertDatabaseMissing('customers', ['name' => 'Cliente Nuevo']);
    }

    public function test_usuario_restringido_no_puede_duplicar_cliente(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $own = Customer::create(['name' => 'Asignado', 'cod' => 'A1', 'country_id' => 2, 'is_active' => true]);
        $this->restrictTo($admin->id, $own->id);

        $this->post(route('business_management.customers.duplicate', $own->slug))
            ->assertRedirect();

        // No se creó ningún clon: sigue habiendo 1 solo cliente en el tenant.
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', 1)->count());
    }

    public function test_usuario_restringido_no_puede_editar_su_cliente_asignado(): void
    {
        $admin = $this->actingAsTenantAdmin(1);
        $own = Customer::create(['name' => 'Asignado', 'cod' => 'A1', 'country_id' => 2, 'is_active' => true]);
        $this->restrictTo($admin->id, $own->id);

        // Solo-lectura: aunque tenga customers.edit, no edita el registro maestro.
        $this->put(route('business_management.customers.update', $own->slug), [
            'name' => 'Renombrado', 'cod' => 'A1', 'country_id' => 2,
        ]);

        $this->assertDatabaseHas('customers', ['id' => $own->id, 'name' => 'Asignado']);
    }

    public function test_usuario_sin_restriccion_si_crea_y_duplica(): void
    {
        $this->actingAsTenantAdmin(1);

        $this->post(route('business_management.customers.store'), [
            'name' => 'Libre', 'cod' => 'L1', 'country_id' => 2,
        ])->assertRedirect();
        $this->assertDatabaseHas('customers', ['name' => 'Libre']);

        $created = Customer::where('name', 'Libre')->firstOrFail();
        $this->post(route('business_management.customers.duplicate', $created->slug))->assertRedirect();
        $this->assertGreaterThanOrEqual(2, Customer::withoutGlobalScopes()->where('tenant_id', 1)->count());
    }
}
