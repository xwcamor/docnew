<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `companies.doc_type` nacio con `default 'RUC'` y NOT NULL, y eso es Peru
 * metido en el esquema.
 *
 * El RUC es el documento de una empresa peruana. En Chile es el RUT, en
 * Argentina el CUIT, en Colombia el NIT. Con ese default, dar de alta una
 * contratista chilena en un pais cuyo catalogo aun no tiene ningun tipo de
 * empresa la guardaba con «RUC» — un documento que en Chile no existe— sin que
 * nadie viera un error. Es el mismo tipo de mentira silenciosa que el «name_pt»
 * relleno con un punto de la v1.
 *
 * Ahora la columna admite nulo y no propone nada: si el catalogo del pais no
 * dice que documento lleva una empresa alli, el sitio correcto para eso es
 * vacio, no una sigla de otro pais. Las filas peruanas que ya tienen RUC se
 * quedan como estan.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('doc_type', 20)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('doc_type', 20)->nullable(false)->default('RUC')->change();
        });
    }
};
