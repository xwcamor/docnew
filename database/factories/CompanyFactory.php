<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Una empresa contratista de mentira, pero valida.
 *
 * La version anterior venia del clon de Brand y solo ponia slug, nombre y
 * estado: `companies` exige ademas pais, RUC y razon social (NOT NULL), asi que
 * cualquier `Company::factory()->create()` moria con un 23502 de Postgres y
 * nadie podia apoyarse en ella para montar un escenario.
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $nombre = Str::upper($this->faker->unique()->lexify('????????'));

        return [
            'slug'          => Str::random(22),
            'name'          => $nombre,
            'complete_name' => $nombre . ' S.A.C.',
            // RUC peruano: 11 digitos, unico por pais dentro del workspace.
            'num_doc'       => '20' . $this->faker->unique()->numerify('#########'),
            'country_id'    => Country::query()->value('id') ?? Country::factory(),
            'is_active'     => true,
        ];
    }

    /** Helper para tests que necesitan un nombre específico (asserts por nombre). */
    public function named(string $name): self
    {
        return $this->state(fn () => ['name' => $name, 'complete_name' => $name . ' S.A.C.']);
    }

    /** Helper para crear companies inactivos en tests de filtro. */
    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
