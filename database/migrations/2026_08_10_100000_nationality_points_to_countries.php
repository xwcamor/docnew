<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La nacionalidad de una persona pasa a ser un pais, y `nationalities` se va.
 *
 * Una nacionalidad ES un pais. Habia dos tablas para lo mismo: `countries` con
 * 26 filas —nombre, ISO, moneda, huso— y `nationalities` con cuatro (Peru,
 * Venezuela, Chile, Argentina), un modulo CRUD completo detras y 35 archivos
 * del proyecto mencionandola.
 *
 * Y ya casi cuesta caro una vez: el tipo de documento se deducia comparando el
 * TEXTO del nombre de la nacionalidad con el TEXTO del nombre del pais. Si la
 * fila se hubiera sembrado como «Peruana» en vez de «Perú», los 224 peruanos
 * habrian salido con carne de extranjeria en vez de DNI. Se salvo por
 * casualidad. Comparando `nationality_id` con `country_id` —numeros— eso deja
 * de poder pasar.
 *
 * Los cuatro valores reales existen ya en `countries`, asi que el traspaso es
 * exacto y no se pierde nada. Lo que no cuadre se deja en nulo antes que
 * inventar una nacionalidad: la nacionalidad es opcional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nationalities')) {
            return;
        }

        $this->traspasarLasPersonas();

        // La clave ajena vieja apunta a una tabla que va a desaparecer.
        Schema::table('people', function ($t) {
            try {
                $t->dropForeign(['nationality_id']);
            } catch (\Throwable) {
                // Puede no existir (SQLite, o una base creada sin ella).
            }
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('people', function ($t) {
                $t->foreign('nationality_id')->references('id')->on('countries')->nullOnDelete();
            });
        }

        Schema::dropIfExists('nationalities');
    }

    /**
     * Cada persona se queda con el pais que corresponde a su nacionalidad.
     *
     * Se empareja por nombre sin tildes ni mayusculas —«Perú» con «Peru»— que
     * es como estan escritas las cuatro, y como respaldo por las tres primeras
     * letras, que distinguen de sobra entre 26 paises.
     */
    private function traspasarLasPersonas(): void
    {
        $limpio = fn (?string $t) => mb_strtolower(preg_replace('/[^a-z]/i', '',
            preg_replace('/\p{Mn}/u', '', \Normalizer::normalize((string) $t, \Normalizer::FORM_D) ?: (string) $t) ?? ''));

        $paises = DB::table('countries')->get(['id', 'name', 'iso_code']);

        foreach (DB::table('nationalities')->get(['id', 'code']) as $nacionalidad) {
            $buscado = $limpio($nacionalidad->code);

            if ($buscado === '') {
                continue;
            }

            $pais = $paises->first(fn ($p) => $limpio($p->name) === $buscado)
                ?? $paises->first(fn ($p) => str_starts_with($limpio($p->name), substr($buscado, 0, 3)));

            DB::table('people')
                ->where('nationality_id', $nacionalidad->id)
                // Sin pais equivalente se deja en nulo: la nacionalidad es
                // opcional, e inventarla seria peor que no tenerla.
                ->update(['nationality_id' => $pais?->id]);
        }
    }

    /**
     * No se vuelve atras.
     *
     * Rehacer `nationalities` es facil; devolverle a cada persona el id que
     * tenia, no: esos ids ya no existen en ningun sitio. Una marcha atras a
     * medias es peor que ninguna, asi que se dice y punto.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'Esta migracion no se puede deshacer: los ids de nationalities se perdieron al traspasarlos a countries.'
        );
    }
};
