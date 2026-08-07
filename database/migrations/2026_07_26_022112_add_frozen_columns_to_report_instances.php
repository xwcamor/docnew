<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Congelado del PDF aprobado: al emitirse la solicitud, el PDF se renderiza UNA
 * vez y se guarda en disco. Estas columnas guardan dónde quedó (path), su huella
 * (sha256, sobrevive a la purga por retención) y cuándo se congeló.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_instances', function (Blueprint $table) {
            $table->string('frozen_path')->nullable()->after('snapshot');
            $table->string('frozen_hash', 64)->nullable()->after('frozen_path');
            $table->timestamp('frozen_at')->nullable()->after('frozen_hash');
        });
    }

    public function down(): void
    {
        Schema::table('report_instances', function (Blueprint $table) {
            $table->dropColumn(['frozen_path', 'frozen_hash', 'frozen_at']);
        });
    }
};
