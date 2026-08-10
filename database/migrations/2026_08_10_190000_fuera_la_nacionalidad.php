<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fuera `people.nationality_id`. Sobraba, y sobraba dos veces.
 *
 * Ya estaba el pais —el del documento, que ademas forma parte de la clave
 * unica de la persona— y ya estaba el tipo de documento, que en Peru dice quien
 * es extranjero sin margen de duda: un peruano lleva DNI y quien viene de fuera
 * lleva carne de extranjeria, PTP o pasaporte. Dos columnas respondiendo lo
 * mismo que una tercera.
 *
 * La nacionalidad existia en la v1 porque alli NO habia tipo de documento:
 * `workers.num_doc` era texto pelado y `nationality_id` era la unica forma de
 * saber quien llevaba carne. De hecho el tipo se DEDUJO de ella al migrar los
 * 391. Cumplio su funcion y se acabo: ahora el que sobra es el original.
 *
 * Es el mismo recorrido que ya hizo la tabla `nationalities`, que tambien se
 * borro por duplicar `countries`. Aquello quito el catalogo; esto quita la
 * pregunta.
 *
 * Quien es extranjero se lee en `Person::getIsForeignerAttribute()`, contra
 * `document_types.for_foreigners`. De que pais concreto es alguien deja de
 * guardarse: no lo pedia ninguna pantalla, ningun informe y ninguna regla — lo
 * unico que se hacia con ello era compararlo con el pais para saber si era de
 * fuera, que es justo lo que ahora dice el documento.
 *
 * `down()` devuelve la columna vacia. Los valores no vuelven, y no pasa nada:
 * eran once filas que nadie leia.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('nationality_id');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->unsignedBigInteger('nationality_id')->nullable()->after('country_id');
        });
    }
};
