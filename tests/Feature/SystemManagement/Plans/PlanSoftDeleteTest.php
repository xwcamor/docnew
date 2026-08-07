<?php

namespace Tests\Feature\SystemManagement\Plans;

use App\Models\Plan;

class PlanSoftDeleteTest extends PlanTestCase
{
    public function test_delete_page_renders(): void
    {
        $this->actingAsSuperAdmin();
        $plan = Plan::factory()->create();

        $this->get(route('system_management.plans.delete', $plan->id))->assertOk();
    }

    public function test_delete_save_soft_deletes_with_reason(): void
    {
        $this->actingAsSuperAdmin();
        $plan = Plan::factory()->create();

        $response = $this->delete(route('system_management.plans.deleteSave', $plan->id), [
            'deleted_description' => 'Plan obsoleto, reemplazado por Plus.',
        ]);

        $response->assertRedirect();
        $this->assertSoftDeleted('plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('plans', [
            'id' => $plan->id,
            'deleted_description' => 'Plan obsoleto, reemplazado por Plus.',
        ]);
    }

    public function test_delete_blocked_if_tenants_use_the_plan(): void
    {
        $this->actingAsSuperAdmin();
        $plan = Plan::factory()->create(['slug' => 'used_plan']);

        // Un tenant "usa" un plan vía suscripción vigente, no vía columna.
        // Creamos una suscripción activa de tenant 1 a este plan.
        \DB::table('subscriptions')->insert([
            'tenant_id' => 1, 'plan' => 'used_plan', 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            'currency' => 'USD', 'payment_method' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->delete(route('system_management.plans.deleteSave', $plan->id), [
            'deleted_description' => 'intentando borrar',
        ]);

        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'deleted_at' => null]);
    }

    public function test_restore_brings_back_soft_deleted_plan(): void
    {
        $this->actingAsSuperAdmin();
        $plan = Plan::factory()->create();
        $plan->delete();

        $response = $this->post(route('system_management.plans.restore', $plan->id));

        $response->assertRedirect();
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'deleted_at' => null]);
    }

    public function test_trash_page_renders_for_super_only(): void
    {
        $this->actingAsSuperAdmin();
        $this->get(route('system_management.plans.trash'))->assertOk();
    }

    public function test_force_delete_requires_name_match(): void
    {
        $this->actingAsSuperAdmin();
        $plan = Plan::factory()->create(['name' => 'Acme Plan']);
        $plan->delete();

        // Nombre incorrecto → no se borra
        $this->delete(route('system_management.plans.force_delete', $plan->id), [
            'name_confirmation' => 'wrong name',
            'reason'            => 'razón de prueba con más de 10 chars',
        ]);
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);

        // Nombre correcto → se borra hard
        $this->delete(route('system_management.plans.force_delete', $plan->id), [
            'name_confirmation' => 'Acme Plan',
            'reason'            => 'razón de prueba con más de 10 chars',
        ]);
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }
}
