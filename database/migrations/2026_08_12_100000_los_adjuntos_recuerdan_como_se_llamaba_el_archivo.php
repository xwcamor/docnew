<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El adjunto guarda el nombre con el que llego el archivo.
 *
 * `form_attachments` guardaba la ruta en disco, y esa ruta es una cadena al
 * azar de 24 caracteres: `formatos/2026/08/xK3p...jpg`. Mientras el formato
 * «HOJA X» era una foto y solo una, daba igual — no habia nada que elegir.
 *
 * Con varios archivos por entrega ya no da igual: la pantalla tiene que poder
 * decir CUAL es cada uno para que se pueda quitar el que se subio por error, y
 * «Documento 3, JPEG, 1,2 MB» no identifica nada cuando son cinco fotos de la
 * misma obra.
 *
 * Nullable a proposito: los adjuntos que ya estan subidos no tienen forma de
 * recuperar su nombre —nunca se guardo— y la pantalla cae al numero de orden
 * cuando esto viene vacio.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('form_attachments', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('form_field_id');
        });
    }

    public function down(): void
    {
        Schema::table('form_attachments', function (Blueprint $table) {
            $table->dropColumn('original_name');
        });
    }
};
