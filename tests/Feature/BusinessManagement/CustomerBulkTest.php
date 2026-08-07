<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;

/**
 * Bulk ops del modulo Customers: bulk_delete, bulk_set_active y
 * undo_last_delete (window de 60s). Patron espejo de Regions.
 */
class CustomerBulkTest extends CustomerTestCase
{
    public function test_bulk_delete_marks_records(): void
    {
        $this->actingAsTenantAdmin(1);

        $a = Customer::factory()->create(['tenant_id' => 1, 'name' => 'A']);
        $b = Customer::factory()->create(['tenant_id' => 1, 'name' => 'B']);
        $c = Customer::factory()->create(['tenant_id' => 1, 'name' => 'C']);

        $response = $this->post(route('business_management.customers.bulk_delete'), [
            'ids'                 => [$a->id, $b->id, $c->id],
            'deleted_description' => 'Limpieza de registros viejos.',
        ]);

        $response->assertRedirect();
        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
        $this->assertNotNull($c->fresh()->deleted_at);
    }

    public function test_bulk_set_active_changes_state(): void
    {
        $this->actingAsTenantAdmin(1);

        $a = Customer::factory()->create(['tenant_id' => 1, 'is_active' => true]);
        $b = Customer::factory()->create(['tenant_id' => 1, 'is_active' => true]);
        $c = Customer::factory()->create(['tenant_id' => 1, 'is_active' => true]);

        $response = $this->post(route('business_management.customers.bulk_set_active'), [
            'ids'       => [$a->id, $b->id, $c->id],
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertFalse((bool) $a->fresh()->is_active);
        $this->assertFalse((bool) $b->fresh()->is_active);
        $this->assertFalse((bool) $c->fresh()->is_active);
    }

    public function test_undo_last_delete_restores(): void
    {
        $this->actingAsTenantAdmin(1);
        $customer = Customer::factory()->create(['tenant_id' => 1, 'name' => 'Para deshacer']);

        // Primero el delete via controller — esto setea la session claim.
        $this->delete(route('business_management.customers.deleteSave', $customer->slug), [
            'deleted_description' => 'Eliminacion temporal.',
        ]);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);

        // Undo dentro del window de 60s.
        $response = $this->post(route('business_management.customers.undo_last_delete'));

        $response->assertRedirect();
        $this->assertDatabaseHas('customers', [
            'id'         => $customer->id,
            'deleted_at' => null,
        ]);
    }
}
