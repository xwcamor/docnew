<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        // `tenants.plan` no existe. Un tenant recién creado sin suscripción
        // = plan `free` derivado (el piso). Tests que necesiten otro plan
        // usan ->withPlan('pro').
        return [
            'name'      => fake()->unique()->company(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function trashed(): static
    {
        return $this->state(fn () => [
            'deleted_at'          => now(),
            'deleted_description' => 'Eliminado en factory.',
        ]);
    }

    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }

    /**
     * Materializa un plan creando una suscripción vigente (el plan se deriva
     * de subscriptions, no de una columna). `free` = sin suscripción, así
     * que withPlan('free') no crea nada.
     */
    public function withPlan(string $plan): static
    {
        return $this->afterCreating(function (Tenant $tenant) use ($plan) {
            if ($plan === 'free') {
                return;
            }
            Subscription::factory()->for($tenant)->create([
                'plan'   => $plan,
                'status' => Subscription::STATUS_ACTIVE,
            ]);
        });
    }
}
