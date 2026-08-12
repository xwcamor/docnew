<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El orden en que un tipo de trabajo pide sus documentos.
 *
 * No existia: `work_type_form_templates` guardaba QUE documentos exige cada
 * tipo, pero no en que orden, asi que la ficha del plan los pintaba como los
 * devolviera la base — o sea por el id del pivote, que es el orden en que
 * alguien fue marcando casillas hace meses.
 *
 * Y el orden importa: en obra los papeles se llenan en una secuencia
 * —primero el AST, despues el permiso, al final las inspecciones— y una lista
 * que no la respeta obliga a buscar el siguiente cada vez.
 *
 * Arranca copiando el id del pivote: deja cada tipo exactamente como se veia
 * hasta hoy, en vez de barajarlo todo por sorpresa al desplegar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_type_form_templates', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0)->after('is_required');
        });

        DB::table('work_type_form_templates')->update([
            'position' => DB::raw('id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('work_type_form_templates', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
