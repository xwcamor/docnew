<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(2);

        return [
            'slug'                   => str_replace('-', '_', $slug),
            'name'                   => $this->faker->words(2, true),
            'tagline'                => $this->faker->sentence(),
            'sort_order'             => $this->faker->numberBetween(1, 100),
            'max_users'              => $this->faker->numberBetween(1, 100),
            'max_records_per_module' => $this->faker->numberBetween(100, 10000),
            'export_rate_limit'      => $this->faker->numberBetween(1, 50),
            'support_level'          => $this->faker->randomElement(Plan::SUPPORT_LEVELS),
            'features'               => [],
            'price_monthly'          => $this->faker->randomFloat(2, 0, 200),
            'price_yearly'           => $this->faker->randomFloat(2, 0, 2000),
            'currency'               => 'USD',
            'is_active'              => true,
            'is_public'              => true,
        ];
    }
}
