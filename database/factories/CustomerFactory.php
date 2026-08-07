<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'slug'      => Str::random(22),
            'name'      => $this->faker->unique()->words(2, true),
            'cod'       => 'C-' . strtoupper(Str::random(6)),
            'is_active' => true,
        ];
    }

    /** Helper para tests que necesitan un nombre específico (asserts por nombre). */
    public function named(string $name): self
    {
        return $this->state(fn () => ['name' => $name]);
    }

    /** Helper para crear customers inactivos en tests de filtro. */
    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
