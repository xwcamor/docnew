<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    public function definition(): array
    {
        // iso_code es char(2) — solo 676 combinaciones posibles (AA-ZZ). El
        // pool de `fake()->unique()->lexify('??')` se agota en suites grandes.
        // Usamos un counter estático que rota A-Z × A-Z determinístico — sin
        // pool finito que se agote, y respeta el shape ISO 3166-1.
        static $counter = 0;
        $counter++;
        $first  = chr(65 + (intdiv($counter, 26) % 26)); // A-Z
        $second = chr(65 + ($counter % 26));             // A-Z
        $iso    = $first . $second;

        return [
            'name'              => fake()->country() . ' ' . \Illuminate\Support\Str::random(4),
            'iso_code'          => $iso,
            'currency'          => strtoupper(fake()->lexify('???')),
            'timezone'          => fake()->timezone(),
            'region_id'         => Region::query()->inRandomOrder()->value('id')
                                    ?? Region::factory()->create()->id,
            'default_locale_id' => DB::table('locales')->inRandomOrder()->value('id') ?? 1,
            'is_active'         => true,
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

    public function forRegion(int $regionId): static
    {
        return $this->state(fn () => ['region_id' => $regionId]);
    }
}
