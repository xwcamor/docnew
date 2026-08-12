<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de bloqueo para los catalogos de obra.
 *
 * Hasta ahora el candado existia en lo que se llena todos los dias —empresas,
 * personas, planes, formatos, marcas— pero no en lo que esos registros
 * apuntan. Y es al reves de como se rompen las cosas: un plan mal escrito lo
 * arregla quien lo escribio, pero renombrar un tipo de trabajo cambia de golpe
 * el papel de los 3.712 planes que ya lo citan, incluidos los cerrados y
 * firmados.
 *
 * El candado se pone a mano, fila a fila, desde la ficha. Aqui solo se crean
 * las columnas. Hubo una version que bloqueaba de entrada todo lo migrado, y
 * salio al reves de lo que se buscaba: ver el comentario de abajo.
 *
 * Los planes NO entran aqui: ya tienen su propio cierre (`is_closed`), que es
 * otra cosa —lo pone el supervisor cuando termina la jornada, no un
 * administrador— y mezclarlos confundiria las dos.
 */
return new class extends Migration
{
    private array $tablas = [
        'work_types', 'work_locations', 'workstations',
        'work_areas', 'positions', 'nationalities', 'approval_rules',
    ];

    public function up(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || Schema::hasColumn($tabla, 'locked_at')) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $t) {
                $t->timestamp('locked_at')->nullable()->index();
                $t->unsignedBigInteger('locked_by')->nullable();
                $t->string('lock_scope', 10)->nullable();
            });

            // Aqui las filas migradas se bloqueaban de entrada, con candado de
            // nivel 'super'. Se quito, por lo mismo que se quito de
            // `docufiz:migrate-data`: `canBeUnlockedBy()` solo deja a un admin
            // con los candados de nivel 'tenant', asi que quien acababa de
            // migrar su sistema se encontraba TODO su catalogo intocable —sus
            // cargos, sus sedes, sus tipos de trabajo— y sin ninguna forma de
            // arreglarlo desde la aplicacion.
            //
            // Lo que se pierde es la pausa antes de renombrar algo que citan
            // miles de planes firmados, y eso sigue siendo cierto. Quien la
            // quiera tiene el candado en la ficha, que es donde se decide fila
            // a fila y no de golpe para todo lo migrado.
            //
            // La columna se queda: el candado manual sigue existiendo.
        }
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'locked_at')) {
                continue;
            }

            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn(['locked_at', 'locked_by', 'lock_scope']));
        }
    }
};
