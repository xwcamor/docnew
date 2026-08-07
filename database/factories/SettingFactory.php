<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        $groups = ['app', 'branding', 'exports', 'mail', 'security'];
        $types  = ['string', 'int', 'bool', 'json'];
        $type   = fake()->randomElement($types);
        $group  = fake()->randomElement($groups);
        // `fake()->unique()->slug(2)` agota su pool tras ~200 generaciones y
        // hace explotar la suite con `OverflowException`. Combinamos slug +
        // sufijo random (Str::random) para garantizar uniqueness sin pool.
        $key    = $group . '.' . fake()->slug(2) . '-' . \Illuminate\Support\Str::random(6);

        return [
            'key'         => $key,
            'name'        => ucwords(str_replace(['.', '_', '-'], ' ', $key)),
            'type'        => $type,
            'value'       => match ($type) {
                'bool' => fake()->boolean() ? 'true' : 'false',
                'int'  => (string) fake()->numberBetween(1, 9999),
                'json' => json_encode(['x' => fake()->word()]),
                default => fake()->sentence(),
            },
            'group'       => $group,
            'description' => fake()->sentence(),
            'is_secret'   => false,
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

    public function ofType(string $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function inGroup(string $group): static
    {
        return $this->state(fn () => ['group' => $group]);
    }

    /** Sobrescribe el name (clone-friendly con Region factory). */
    public function named(string $name): static
    {
        return $this->state(fn () => ['name' => $name]);
    }
}
