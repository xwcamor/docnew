<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los tipos de trabajo recuperan su nombre.
 *
 * La tabla nacio con una sola columna textual, `code`, y la migracion de la v1
 * metio ahi lo unico que traia: el nombre completo («Izaje y Montaje de
 * estructuras»). O sea que el codigo ES el nombre, y el modulo pedia «Codigo»
 * cuando lo que el usuario escribia era un nombre. Pregunta del dueño del
 * producto mirando la pantalla: «¿por que en tipo de trabajo no creaste el
 * campo nombre y en codigo pusiste el nombre?».
 *
 * A partir de aqui son dos cosas distintas, como en `form_templates`: el codigo
 * es la sigla corta con la que se le cita («IZAJE») y el nombre es lo que se
 * lee en pantalla. Las filas existentes copian su codigo al nombre — es el
 * nombre que siempre fue, no se pierde nada — y quien quiera acortar el codigo
 * lo edita despues desde la ficha.
 *
 * `name` es nullable a proposito: el codigo sigue siendo la identidad (unica
 * por pais, la clave del pivote de documentos y de la migracion de planes), y
 * una fila vieja sin nombre no debe reventar nada — el modelo cae a `code` con
 * su accesor `label`.
 *
 * Nullable y sin clave ajena: SQLite no reconstruye la tabla y no hace falta el
 * guarda de `getDriverName() !== 'sqlite'`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_types', 'name')) {
            Schema::table('work_types', function (Blueprint $table) {
                $table->string('name')->nullable()->after('code')
                    ->comment('Lo que se lee en pantalla; el codigo queda como sigla corta');
            });
        }

        // El codigo de hoy es el nombre migrado: se copia tal cual. Solo las
        // filas sin nombre, para que re-correr la migracion no pise un nombre
        // que alguien ya edito.
        DB::table('work_types')
            ->whereNull('name')
            ->update(['name' => DB::raw('code')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_types', 'name')) {
            Schema::table('work_types', fn (Blueprint $table) => $table->dropColumn('name'));
        }
    }
};
