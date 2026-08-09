<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Que caracteres admite el numero de cada tipo de documento.
 *
 * La tabla ya decia CUANTOS (`min_length`/`max_length`) pero no CUALES, asi que
 * un DNI aceptaba letras y espacios. En la base maestra de la v1 se ve lo que
 * eso produce: un numero de celular en el campo del documento, un «11111111»
 * inventado, y nombres con dos y seis espacios seguidos.
 *
 * Tres valores:
 *   - `digits`       solo cifras. El DNI peruano, el carne de extranjeria, el PTP.
 *   - `alphanumeric` cifras y letras. El pasaporte.
 *   - null           sin restriccion, para un pais cuyo documento no se conoce.
 *
 * Los espacios no entran en ninguno de los dos: no hay documento del mundo que
 * los lleve dentro, y un espacio invisible al final parte en dos a la misma
 * persona —una version con y otra sin— que es justo lo que hubo que unificar a
 * mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_types') || Schema::hasColumn('document_types', 'allowed_chars')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $t) {
            $t->string('allowed_chars', 20)->nullable()->after('max_length');
        });

        // Lo que ya hay sembrado. El pasaporte lleva letras; el resto no.
        DB::table('document_types')->whereIn('code', ['DNI', 'CE', 'PTP'])->update(['allowed_chars' => 'digits']);
        DB::table('document_types')->where('code', 'PASAPORTE')->update(['allowed_chars' => 'alphanumeric']);

        // El DNI se habia sembrado con minimo 7 por dos peruanos del volcado que
        // lo tenian corto; al repasar la base maestra no queda ninguno. Se sube
        // a 8 SOLO donde siga estando el 7 sembrado: si alguien lo cambio a mano
        // desde la pantalla, esa decision es suya y no se pisa.
        DB::table('document_types')->where('code', 'DNI')->where('min_length', 7)->update(['min_length' => 8]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_types', 'allowed_chars')) {
            Schema::table('document_types', fn (Blueprint $t) => $t->dropColumn('allowed_chars'));
        }
    }
};
