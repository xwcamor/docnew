<?php

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        // iso_code ISO 639-1: exactamente 2 letras lowercase (`^[a-z]{2}$`),
        // exigido por UpdateRequest/StoreRequest. Pool de `unique()->lexify('??')`
        // es 676 y se agota en suites grandes. Counter estático rota A-Z × A-Z
        // de forma determinística sin pool finito.
        static $counter = 0;
        $counter++;
        $first  = chr(97 + (intdiv($counter, 26) % 26)); // a-z
        $second = chr(97 + ($counter % 26));             // a-z

        return [
            'name'      => fake()->words(2, true) . ' ' . \Illuminate\Support\Str::random(4),
            'iso_code'  => $first . $second,
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
