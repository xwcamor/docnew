<?php

use App\Models\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quien es extranjero lo dice su documento, no una nacionalidad aparte.
 *
 * En Peru un peruano lleva DNI y un extranjero lleva carne de extranjeria o
 * PTP. El dato ya estaba en la ficha —el tipo de documento— y encima de el
 * habia una segunda pregunta, la nacionalidad, para responder lo mismo.
 *
 * La nacionalidad existia en la v1 porque alli NO habia tipo de documento:
 * `workers.num_doc` era texto pelado y `nationality_id` era la unica forma de
 * saber quien llevaba carne. De hecho asi se dedujo el tipo al migrar los 391.
 * Ahora hay tipo, y la que sobra es la nacionalidad.
 *
 * Sobra como PREGUNTA, no como dato: la columna `people.nationality_id` se
 * queda con lo que trajo la migracion —de que pais son once personas— porque
 * borrarla no devuelve nada y perderla si. Lo que desaparece es el campo del
 * formulario y la columna del Excel.
 *
 * Para poder deducirlo hace falta que el catalogo diga que documento lleva un
 * extranjero, que es justo lo que no estaba escrito en ninguna parte.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('for_foreigners')->default(false)->after('allowed_chars')
                ->comment('Lo lleva quien no es del pais: carne de extranjeria, PTP, pasaporte');
        });

        // Peru. El pasaporte cuenta como de extranjero a proposito: un peruano
        // que trabaja en obra se identifica con su DNI, asi que quien llega con
        // pasaporte viene de fuera.
        DB::table('document_types')
            ->where('scope', DocumentType::PERSONA)
            ->whereIn('code', ['CE', 'PTP', 'PASAPORTE'])
            ->update(['for_foreigners' => true]);
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('for_foreigners'));
    }
};
