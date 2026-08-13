<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Como se imprime cada formato lo decide el formato, no el codigo.
 *
 * La orientacion estaba cableada: se miraba si el formato llevaba una matriz de
 * riesgo y en ese caso salia apaisado. Servia para el AST y era falso para todo
 * lo demas — el EPP y la inspeccion de herramientas son cuadriculas anchas sin
 * ninguna matriz, y en vertical salen con las columnas en tiras de dos letras.
 *
 * Lo dijo el dueño del producto: «esto de decir que pongas las hojas en
 * horizontal esta mal, no crees que deba haber una configuracion en formatos?».
 * Tiene razon: el motor deja definir formatos desde la pantalla, y adivinar
 * como se imprime uno nuevo mirando que campos lleva es adivinar.
 *
 * `null` conserva el comportamiento de antes: se deduce. Quien no toque nada no
 * nota el cambio, y quien quiera decidirlo lo dice en la ficha del documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_templates', function (Blueprint $table) {
            $table->string('pdf_orientation', 10)->nullable()->after('pdf_template')
                ->comment('portrait | landscape | null = se deduce del contenido');
        });

        // Los cuatro de obra, dichos por su nombre en vez de deducidos.
        // Los tres primeros son cuadriculas anchas; el PTF es un cuestionario
        // de diecisiete preguntas largas y se lee mejor en vertical, pero
        // arrastra una matriz de riesgo — asi que tambien apaisado, que es lo
        // que la deduccion ya hacia con el.
        DB::table('form_templates')->whereIn('code', ['AST', 'PTF', 'EPP', 'IHM'])
            ->update(['pdf_orientation' => 'landscape']);
    }

    public function down(): void
    {
        Schema::table('form_templates', function (Blueprint $table) {
            $table->dropColumn('pdf_orientation');
        });
    }
};
