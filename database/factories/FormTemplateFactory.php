<?php

namespace Database\Factories;

use App\Models\FormTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Un documento de mentira, para pruebas.
 *
 * Venia del clon de `Brand` y ponia solo `slug`, `name` e `is_active`: le
 * faltaban `country_id` y `code`, **las dos NOT NULL**, asi que cualquier
 * prueba que usara la factory reventaba con un 23502. Nadie lo noto porque el
 * modulo no tenia ni una prueba.
 *
 * `country_id` no se inventa —igual que en `ApprovalRuleFactory`—: un documento
 * pertenece a un pais y la prueba tiene que decir a cual. `code` si se deriva
 * del nombre, que es lo que hace el formulario cuando se deja en blanco.
 */
class FormTemplateFactory extends Factory
{
    protected $model = FormTemplate::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);

        return [
            'slug'      => Str::random(22),
            'name'      => $name,
            'code'      => mb_substr(preg_replace('/\s+/', '_', Str::lower($name)), 0, 40),
            'kind'      => FormTemplate::STRUCTURED,
            'status'    => 'draft',
            'version'   => 1,
            'is_active' => true,
        ];
    }

    /** Helper para tests que necesitan un nombre específico (asserts por nombre). */
    public function named(string $name): self
    {
        return $this->state(fn () => [
            'name' => $name,
            'code' => mb_substr(preg_replace('/\s+/', '_', Str::lower($name)), 0, 40),
        ]);
    }

    /** Ya publicado: es el único estado en el que un plan lo ve. */
    public function published(): self
    {
        return $this->state(fn () => ['status' => 'published', 'published_at' => now()]);
    }

    /** Sólo se sube la foto del papel: se publica sin definirle campos. */
    public function uploadOnly(): self
    {
        return $this->state(fn () => ['kind' => FormTemplate::UPLOAD_ONLY]);
    }

    /** Helper para crear documentos inactivos en tests de filtro. */
    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
