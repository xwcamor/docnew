<?php

namespace Database\Factories;

use App\Models\SystemModule;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemModuleFactory extends Factory
{
    protected $model = SystemModule::class;

    public function definition(): array
    {
        // El Model auto-deriva permission_key del name vía setNameAttribute.
        // `fake()->unique()->word()` agota su pool en suites largas — usamos
        // word + sufijo random para uniqueness sin colisión.
        $name = ucfirst(fake()->word()) . \Illuminate\Support\Str::random(4);
        return [
            'name'      => $name,
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
}
