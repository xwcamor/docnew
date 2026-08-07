<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $starts = now()->subDays($this->faker->numberBetween(1, 30));
        $ends   = (clone $starts)->addYear();

        return [
            'tenant_id'      => Tenant::factory(),
            // 'free' no lleva suscripción (es la ausencia de una) — el factory
            // solo genera planes pagos.
            'plan'           => $this->faker->randomElement(['basic', 'pro', 'enterprise']),
            'status'         => Subscription::STATUS_ACTIVE,
            'starts_at'      => $starts,
            'ends_at'        => $ends,
            'trial_ends_at'  => null,
            'amount_paid'    => $this->faker->randomFloat(2, 0, 1000),
            'currency'       => 'USD',
            'payment_method' => 'manual',
            'created_by'     => null,
        ];
    }

    public function trial(int $days = 14): static
    {
        return $this->state(fn () => [
            'status'        => Subscription::STATUS_TRIAL,
            'starts_at'     => now(),
            'ends_at'       => now()->addDays($days),
            'trial_ends_at' => now()->addDays($days),
            'amount_paid'   => 0,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'    => Subscription::STATUS_EXPIRED,
            'starts_at' => now()->subYear(),
            'ends_at'   => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'              => Subscription::STATUS_CANCELLED,
            'cancelled_at'        => now()->subDay(),
            'cancellation_reason' => 'Customer requested',
        ]);
    }

    public function plan(string $plan): static
    {
        return $this->state(fn () => ['plan' => $plan]);
    }
}
