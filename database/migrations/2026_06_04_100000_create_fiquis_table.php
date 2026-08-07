<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fiquis — muestras de análisis fisicoquímico del aceite (norma IEEE C57.106).
 *
 * Cinco parámetros: rigidez dieléctrica (rig), tensión interfacial (ten), acidez
 * (acid), contenido de agua (wat), factor de potencia (pot). El diagnóstico
 * (score/condición/rating) se cachea aquí; los umbrales viven en fiquis_rules.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiquis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('transformers')->cascadeOnDelete();
            $table->dateTime('sample_date'); // fecha + hora del muestreo

            $table->decimal('rig', 10, 3)->nullable();  // rigidez dieléctrica (kV)
            $table->decimal('rig877', 12, 3)->nullable(); // rigidez dieléctrica D877
            $table->decimal('ten', 10, 3)->nullable();  // tensión interfacial (mN/m)
            $table->decimal('acid', 10, 4)->nullable(); // acidez (mg KOH/g)
            $table->decimal('wat', 10, 2)->nullable();  // contenido de agua (ppm)
            $table->decimal('pot', 10, 4)->nullable();  // factor de potencia (%)
            $table->decimal('pot100', 12, 4)->nullable(); // factor de potencia 100 °C

            // Diagnóstico cacheado.
            $table->decimal('score', 6, 2)->nullable();
            $table->decimal('rating', 4, 1)->nullable(); // 4..0 para el Índice de Salud
            $table->string('condition', 60)->nullable();

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transformer_id', 'sample_date'], 'fiquis_transformer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiquis');
    }
};
