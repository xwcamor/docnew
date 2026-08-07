<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relación del firmante con el informe (etapa 2 de firmas): clave cerrada y
 * TRADUCIBLE (prepared|reviewed|approved|authorized|verified|endorsed). Sale en
 * el PDF en el idioma del informe ("Aprobado por" / "Approved by"). El cargo
 * (title) sigue siendo texto libre del workspace. Default 'approved'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_signers', function (Blueprint $table) {
            $table->string('relation', 20)->default('approved')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('report_signers', function (Blueprint $table) {
            $table->dropColumn('relation');
        });
    }
};
