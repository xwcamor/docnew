<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selección MANUAL de muestras para la Tabla 4 (tasa de generación, IEEE C57.104).
 * NULL = automático (el motor elige las últimas 3-6 muestras en 4-24 meses).
 * Array de IDs de cromatografía = el diagnosticador eligió a mano cuáles usar.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->json('dga_rate_sample_ids')->nullable()->after('ieee_condition');
        });
    }

    public function down(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->dropColumn('dga_rate_sample_ids');
        });
    }
};
