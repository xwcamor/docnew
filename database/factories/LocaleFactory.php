<?php

namespace Database\Factories;

use App\Models\Language;
use App\Models\Locale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locale>
 */
class LocaleFactory extends Factory
{
    protected $model = Locale::class;

    public function definition(): array
    {
        // BCP-47 short: ll_CC (regex `^[a-z]{2}(_[A-Z]{2})?$`, max:10). Counter
        // estático rota combinaciones determinísticas — el pool de
        // `fake()->unique()->lexify('??')` es 676 y se agota en suites grandes.
        static $counter = 0;
        $counter++;
        $a = chr(97 + (intdiv($counter, 26) % 26)); // a-z
        $b = chr(97 + ($counter % 26));             // a-z
        $A = chr(65 + (intdiv($counter, 26) % 26)); // A-Z
        $B = chr(65 + ($counter % 26));             // A-Z

        return [
            'code'        => "{$a}{$b}_{$A}{$B}",
            'name'        => fake()->words(2, true) . ' ' . \Illuminate\Support\Str::random(4),
            'language_id' => Language::query()->inRandomOrder()->value('id')
                              ?? Language::factory()->create()->id,
            'is_active'   => true,
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

    public function forLanguage(int $languageId): static
    {
        return $this->state(fn () => ['language_id' => $languageId]);
    }
}
