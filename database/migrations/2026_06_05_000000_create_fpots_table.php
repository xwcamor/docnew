<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fpots — muestras de Factor de Potencia del aislamiento (num_fac, en %).
 *
 * Test de valor único, mismo patrón que furanos: cada fila es una toma con su
 * valor; el rating del Índice de Salud sale de ubicar `value` en la escala de
 * fpot (result_scales, DATOS). Per-tenant + auditable + soft-delete.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fpots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('transformers')->cascadeOnDelete();
            $table->dateTime('sample_date'); // fecha + hora del muestreo
            $table->decimal('value', 8, 3)->nullable();   // num_fac — factor de potencia (%)
            $table->decimal('temperature', 6, 2)->nullable(); // °C de la medición (opcional, informativo)

            $table->decimal('rating', 4, 1)->nullable();  // 4..0 para el Índice de Salud (caché)
            $table->string('condition', 60)->nullable();

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transformer_id', 'sample_date'], 'fpots_transformer_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fpots');
    }
};
