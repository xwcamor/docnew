<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caché de vida remanente del papel: años estimados hasta DP<200 (fin de vida
 * del aislamiento) extrapolando la tendencia del grado de polimerización desde
 * el historial de furanos. Se recalcula al evaluar el HI (igual que las otras
 * cachés de flota). Backfill: `php artisan diagnose:fleet-cache`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            // Años hasta fin de vida del papel (DP<200). null = sin proyección
            // (sin historial suficiente, papel no envejeciendo, o ya en fin de vida).
            $table->decimal('paper_life_years', 5, 1)->nullable()->index()->after('paper_dp');
        });
    }

    public function down(): void
    {
        Schema::table('transformers', function (Blueprint $table) {
            $table->dropColumn('paper_life_years');
        });
    }
};
