<?php

namespace Tests\Feature\SystemManagement\Plans;

use App\Models\Plan;

class PlanCrudTest extends PlanTestCase
{
    public function test_index_renders_for_super(): void
    {
        $this->actingAsSuperAdmin();
        Plan::factory()->count(3)->create();

        $this->get(route('system_management.plans.index'))->assertOk();
    }

    public function test_admin_role_cannot_access_plans(): void
    {
        $this->actingAsAdmin();
        // El handler global redirige 403s al dashboard con flash. Verificamos
        // que NO recibe contenido (sería 200 si tuviera acceso).
        $response = $this->get(route('system_management.plans.index'));
        $this->assertContains($response->status(), [302, 403], 'admin no debería ver el listado de planes');
    }

    public function test_store_persists_a_new_plan(): void
    {
        $this->actingAsSuperAdmin();

        $response = $this->post(route('system_management.plans.store'), [
            'slug'                   => 'starter',
            'name'                   => 'Starter',
            'tagline'                => 'Plan inicial',
            'max_users'              => 3,
            'max_records_per_module' => 1000,
            'export_rate_limit'      => 2,
            'support_level'          => 'email',
            'price_monthly'          => 19.99,
            'price_yearly'           => 199.0,
            'currency'               => 'USD',
            'is_active'              => true,
            'is_public'              => true,
            'features'               => ['export_pdf' => true, 'audit_log_view' => true],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('plans', ['slug' => 'starter', 'name' => 'Starter']);
    }

    public function test_update_modifies_plan_fields(): void
    {
        $this->actingAsSuperAdmin();
        $plan = Plan::factory()->create(['name' => 'Old']);

        $response = $this->put(route('system_management.plans.update', $plan->id), [
            'name'                   => 'New Name',
            'max_users'              => 99,
            'max_records_per_module' => 50000,
            'export_rate_limit'      => 10,
            'support_level'          => 'priority',
            'price_monthly'          => 0,
            'price_yearly'           => 0,
            'currency'               => 'USD',
            'features'               => ['api_access' => true],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('plans', ['id' => $plan->id, 'name' => 'New Name']);
    }

    public function test_active_slugs_returns_only_active_plans(): void
    {
        Plan::factory()->create(['slug' => 'p1', 'is_active' => true]);
        Plan::factory()->create(['slug' => 'p2', 'is_active' => false]);
        Plan::factory()->create(['slug' => 'p3', 'is_active' => true]);

        $slugs = Plan::activeSlugs();

        $this->assertContains('p1', $slugs);
        $this->assertContains('p3', $slugs);
        $this->assertNotContains('p2', $slugs);
    }

    public function test_max_users_negative_one_is_unlimited(): void
    {
        $plan = Plan::factory()->create(['max_users' => -1]);
        $this->assertSame(PHP_INT_MAX, $plan->max_users);
    }

    public function test_has_feature_returns_value_from_json(): void
    {
        $plan = Plan::factory()->create([
            'features' => ['api_access' => true, 'export_pdf' => false],
        ]);
        $this->assertTrue($plan->hasFeature('api_access'));
        $this->assertFalse($plan->hasFeature('export_pdf'));
        $this->assertFalse($plan->hasFeature('nonexistent'));
    }
}
