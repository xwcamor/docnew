<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `positions` también viene del sistema anterior, y le faltaba la trazabilidad.
 *
 * Los cargos —Técnico, Supervisor, Mecánico, Eléctrico— se habían quedado sin
 * migrar. No es un catálogo decorativo: en la v1 `workers.position_id` es NOT
 * NULL, o sea que **los 372 trabajadores tienen cargo**, y la ficha del plan lo
 * enseñaba debajo del nombre de cada uno. Aquí llegaron los 372 sin ninguno.
 *
 * Sin `legacy_id` el comando no puede reconocer lo que ya migró y volvería a
 * crear los cargos en cada pasada, como el resto de catálogos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->index('legacy_id', 'idx_positions_legacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('idx_positions_legacy_id');
            $table->dropColumn('legacy_id');
        });
    }
};
