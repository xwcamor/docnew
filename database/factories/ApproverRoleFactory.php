<?php

namespace Database\Factories;

use App\Models\ApproverRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Un rol aprobador de mentira, para pruebas.
 *
 * El codigo se saca de un contador y no de faker: es unico por workspace y un
 * `word()` repetido tumbaria el indice a mitad de una prueba, con un error de
 * base de datos que no dice nada de lo que se estaba probando.
 */
class ApproverRoleFactory extends Factory
{
    protected $model = ApproverRole::class;

    public function definition(): array
    {
        $n = static::$secuencia++;

        return [
            'slug'       => Str::random(22),
            'code'       => 'rol_' . $n,
            'name_es'    => 'Rol ' . $n,
            'name_en'    => 'Role ' . $n,
            'sort_order' => $n,
            'is_active'  => true,
        ];
    }

    /** Contador de la instancia de prueba: garantiza codigos distintos. */
    protected static int $secuencia = 1;

    /** Un rol con un codigo concreto, para las pruebas que lo nombran. */
    public function code(string $code): self
    {
        return $this->state(fn () => ['code' => $code]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
