<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caché del pronóstico de riesgo a corto plazo (meses hasta cruzar a una banda
 * peor del semáforo de cromas) + la banda destino. Se calcula al diagnosticar
 * (HealthIndexService::evaluate, solo para los que empeoran) y el dashboard lo
 * LEE, en vez de recalcular ~40 trafos en vivo en cada carga (era el cuello de
 * botella: ~8-9s). Backfill: php artisan diagnose:fleet-cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->decimal('forecast_months', 6, 1)->nullable()->index()->after('paper_life_years');
            $table->string('forecast_target', 60)->nullable()->after('forecast_months');
        });
    }

    public function down(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->dropColumn(['forecast_months', 'forecast_target']);
        });
    }
};
