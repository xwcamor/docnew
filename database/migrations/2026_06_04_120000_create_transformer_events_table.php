<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transformer_events — bitácora del transformador: comentarios/eventos para armar
 * una línea de tiempo y un kanban de "qué pasó".
 *
 * Un evento puede ser PUNTUAL (solo starts_at) o de TRAMO (starts_at..ends_at).
 * `status` define la columna del kanban; `category` es una etiqueta del tipo de
 * evento (observación, mantenimiento, alarma, ensayo…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transformer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transformer_id')->constrained('transformers')->cascadeOnDelete();

            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('status', 30)->default('pendiente');   // columna del kanban
            $table->string('category', 30)->nullable();           // observacion / mantenimiento / alarma / ensayo
            $table->string('color', 16)->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();              // si viene = tramo

            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['transformer_id', 'starts_at'], 'tr_events_transformer_date_idx');
            $table->index(['transformer_id', 'status'], 'tr_events_transformer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transformer_events');
    }
};
