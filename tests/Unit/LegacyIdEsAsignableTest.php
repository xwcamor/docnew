<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Todo modelo con columna `legacy_id` tiene que poder escribirla.
 *
 * Este es el fallo mas silencioso que ha salido en toda la revision. La columna
 * existe, la migracion le pasa el valor, Eloquent lo descarta porque no esta en
 * el `$fillable` — y **no se queja nadie**. No hay excepcion, no hay aviso, no
 * hay nada en el log. La fila se guarda; solo que sin la marca de donde viene.
 *
 * Lo que se pierde con eso no es la marca, es lo que cuelga de ella: el
 * backfill del candado busca por `legacy_id`, no encuentra la fila, y el
 * catalogo migrado se queda sin bloquear. Renombrarlo cambia de golpe lo que
 * dicen los 3.712 planes que lo citan, cerrados y firmados incluidos. Y la
 * siguiente pasada de la migracion tampoco lo reconoce.
 *
 * Estaba en `Position`. Los otros nueve modelos que migran estaban bien, pero
 * eso hoy: el proximo que alguien anada se escribe a mano, y a mano se olvida.
 */
class LegacyIdEsAsignableTest extends TestCase
{
    use RefreshDatabase;

    public function test_todo_modelo_con_legacy_id_lo_tiene_en_fillable(): void
    {
        $sinAsignar = [];

        foreach ($this->modelos() as $clase) {
            $modelo = new $clase();

            if (! Schema::hasColumn($modelo->getTable(), 'legacy_id')) {
                continue;
            }

            // `$guarded = []` tambien vale: deja pasar cualquier columna.
            if ($modelo->getFillable() === [] && $modelo->getGuarded() === []) {
                continue;
            }

            if (! $modelo->isFillable('legacy_id')) {
                $sinAsignar[] = class_basename($clase);
            }
        }

        $this->assertSame([], $sinAsignar,
            "Estos modelos tienen columna `legacy_id` pero la descartan al guardar, sin avisar.\n"
            . "La fila se guarda sin marca de origen: el candado no se aplica y la siguiente\n"
            . "migracion no la reconoce. Anadir 'legacy_id' a su \$fillable:\n  "
            . implode("\n  ", $sinAsignar));
    }

    /**
     * Los modelos de la app que tienen tabla propia.
     *
     * @return array<int, class-string<Model>>
     */
    private function modelos(): array
    {
        $salida = [];

        foreach (File::files(app_path('Models')) as $archivo) {
            $clase = 'App\\Models\\' . $archivo->getFilenameWithoutExtension();

            if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
                continue;
            }

            $refl = new \ReflectionClass($clase);

            if ($refl->isAbstract()) {
                continue;
            }

            // Un modelo sin tabla (pivotes sueltos, modelos de apoyo) no cuenta.
            try {
                if (! Schema::hasTable((new $clase())->getTable())) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $salida[] = $clase;
        }

        return $salida;
    }
}
