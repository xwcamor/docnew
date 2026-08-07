<?php

namespace Database\Factories;

use App\Models\TapChangerBrand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TapChangerBrandFactory extends Factory
{
    protected $model = TapChangerBrand::class;

    public function definition(): array
    {
        return [
            'slug'      => Str::random(22),
            'name'      => $this->faker->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    /** Helper para tests que necesitan un nombre específico (asserts por nombre). */
    public function named(string $name): self
    {
        return $this->state(fn () => ['name' => $name]);
    }

    /** Helper para crear tapChangerBrands inactivos en tests de filtro. */
    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
