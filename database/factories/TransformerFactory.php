<?php

namespace Database\Factories;

use App\Models\Transformer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TransformerFactory extends Factory
{
    protected $model = Transformer::class;

    public function definition(): array
    {
        return [
            'slug'      => Str::random(22),
            'serial'    => $this->faker->unique()->bothify('SN-#####'),
            'tag'       => $this->faker->bothify('TR-##'),
        ];
    }

    /** Helper para tests que necesitan un identificador específico (asserts por serial). */
    public function named(string $name): self
    {
        return $this->state(fn () => ['serial' => $name]);
    }

}
