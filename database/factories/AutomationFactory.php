<?php

namespace Database\Factories;

use App\Models\Automation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutomationFactory extends Factory
{
    protected $model = Automation::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => 1,
            'name'           => $this->faker->sentence(3),
            'description'    => $this->faker->sentence(),
            'is_active'      => true,
            'trigger_type'   => 'schedule',
            'trigger_config' => ['kind' => 'daily', 'time' => '09:00'],
            'data_source'    => null,
            'data_filter'    => ['where' => [], 'limit' => 100],
            'action_type'    => 'email',
            'action_config'  => [
                'to'      => ['admin@example.com'],
                'subject' => 'Test',
                'body'    => 'Body',
            ],
            'next_run_at'    => now()->addHour(),
        ];
    }
}
