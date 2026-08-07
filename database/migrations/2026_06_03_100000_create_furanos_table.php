<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * furanos — muestra de análisis de furanos de un transformador.
 *
 * Mide compuestos furánicos (ppb). El que diagnostica es `fal`
 * (2-furfuraldehído): el rating del Índice de Salud sale de fal/1000 (ppm)
 * ubicado en la escala de furanos (result_scales). Mismo patrón que
 * chromatographicals.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('furanos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('transformers')->cascadeOnDelete();
            $table->dateTime('sample_date'); // fecha + hora del muestreo

            // Compuestos furánicos (ppb). fal es el que produce el diagnóstico.
            $table->decimal('fal', 12, 3)->nullable(); // 2-furfuraldehído
            $table->decimal('hme', 12, 3)->nullable(); // 5-hidroxi-metil-furfuraldehído
            $table->decimal('ace', 12, 3)->nullable(); // 2-acetilfurano
            $table->decimal('mfu', 12, 3)->nullable(); // 5-metil-2-furfuraldehído
            $table->decimal('fua', 12, 3)->nullable(); // 2-furfuril alcohol
            $table->integer('dp')->nullable();         // grado de polimerización

            // Caché del diagnóstico de esta muestra.
            $table->decimal('rating', 4, 1)->nullable(); // 4..0 para el Índice de Salud
            $table->string('condition', 60)->nullable();

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transformer_id', 'sample_date'], 'furanos_transformer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('furanos');
    }
};
