<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * Por eso los catalogos que trajo la migracion quedan bloqueados de entrada
 * (ver `docufiz:migrate-data`): son la referencia del sistema anterior y no se
 * tocan por accidente. Quien quiera cambiarlos tiene que quitar el candado
 * primero, que es exactamente la pausa que faltaba.
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

            // Lo que ya trajo la migracion de la v1 se bloquea aqui mismo: de
            // ahora en adelante lo hace `docufiz:migrate-data` al crear cada
            // fila, pero las que ya estaban puestas no pasarian por ahi otra vez.
            // `legacy_id` es justo lo que las distingue de lo que se dio de alta
            // a mano, que se queda como esta.
            if (Schema::hasColumn($tabla, 'legacy_id')) {
                DB::table($tabla)->whereNotNull('legacy_id')->update([
                    'locked_at'  => now(),
                    'locked_by'  => 1,
                    'lock_scope' => 'super',
                ]);
            }
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
