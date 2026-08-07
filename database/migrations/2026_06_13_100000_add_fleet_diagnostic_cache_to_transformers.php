<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caché de diagnóstico de flota en la tabla transformers. Estos valores se
 * recalculan cuando se evalúa el HI del trafo (HealthIndexService::evaluate),
 * para que el dashboard de flota los AGREGUE leyendo columnas (rápido) en vez
 * de recomputar Duval/IEEE/tasas por cada trafo en cada carga.
 *
 * Backfill de datos existentes: `php artisan diagnose:fleet-cache`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            // Familia de falla del último cromas (Duval T1): pd / discharge /
            // thermal / mixed / normal. null = sin clasificar (sin cromas).
            $table->string('fault_type', 24)->nullable()->index()->after('health_trend');
            // Tasa de generación de gas (TDCG ppm/mes) entre las 2 últimas
            // muestras. >0 = gasificación activa (alarma IEEE C57.104).
            $table->decimal('gassing_rate', 10, 2)->nullable()->after('fault_type');
            // Grado de polimerización del papel (último furano con dp). <200 =
            // fin de vida. Denormalizado para agregaciones de flota.
            $table->integer('paper_dp')->nullable()->index()->after('gassing_rate');
            // Peor nivel de condición IEEE C57.104 del último cromas (1..4).
            $table->unsignedTinyInteger('ieee_condition')->nullable()->index()->after('paper_dp');
        });
    }

    public function down(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->dropColumn(['fault_type', 'gassing_rate', 'paper_dp', 'ieee_condition']);
        });
    }
};
