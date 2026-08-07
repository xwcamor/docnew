<?php

namespace Database\Factories;

use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    protected $model = Region::class;

    public function definition(): array
    {
        // `fake()->unique()->words(2, true)` agota el pool de Faker tras
        // ~200 iteraciones — flaky en suite completa. Suffix random garantiza
        // uniqueness sin depender del pool de palabras.
        return [
            'name'      => fake()->words(2, true) . ' ' . Str::random(4),
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
