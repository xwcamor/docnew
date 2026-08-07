<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de fisicoquímico en BD relacional (antes vivían en fiquis_rules.json /
 * blob en diagnostic_datasets). El JSON queda solo como SEEDER de fábrica.
 *
 *  - fiqui_params:     registro de parámetros (rig, ten, … + custom). Peso,
 *                      dirección, unidad, método ASTM, modo (puntúa/solo-límite).
 *  - fiqui_thresholds: umbrales por parámetro × aceite × clase de tensión.
 *
 * Una variable nueva (ej. color) = 1 fila en fiqui_params + N filas en
 * fiqui_thresholds (una por aceite/clase). Todo consultable y auditable por SQL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiqui_params', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // rig, ten, color…
            $table->string('label')->nullable();          // etiqueta legible opcional
            $table->decimal('weight', 6, 2)->nullable();  // peso en el DGAF (null = no puntúa)
            $table->string('dir');                        // higher | lower | lower_strict
            $table->string('unit')->nullable();
            $table->string('astm')->nullable();           // método (ASTM/IEC)
            $table->string('mode')->default('scored');    // scored | limit
            $table->decimal('tol', 6, 4)->nullable();     // tolerancia (modo limit)
            $table->boolean('scored')->default(true);     // entra al DGAF
            $table->integer('display_order')->default(0);
            $table->boolean('is_core')->default(false);   // true = parámetro de norma (no se borra)
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'display_order']);
        });

        Schema::create('fiqui_thresholds', function (Blueprint $table) {
            $table->id();
            $table->string('param_key');                  // FK lógica a fiqui_params.key
            $table->string('oil_code');                   // mineral, silicona, vegetal_soya…
            $table->string('voltage_class');              // low | mid | high | all
            $table->decimal('t1', 14, 4)->nullable();
            $table->decimal('t2', 14, 4)->nullable();
            $table->decimal('t3', 14, 4)->nullable();
            $table->timestamps();
            $table->unique(['param_key', 'oil_code', 'voltage_class'], 'fiqui_thr_unique');
            $table->index('oil_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiqui_thresholds');
        Schema::dropIfExists('fiqui_params');
    }
};
