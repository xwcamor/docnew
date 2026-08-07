<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos base del motor de diagnóstico.
 *
 * Son tablas-catálogo simples y editables. Todo lo que el sistema viejo tenía
 * clavado en el código (tipos de aceite, tipos de trafo, normas) ahora vive
 * aquí como datos. Agregar un aceite o una norma = agregar una fila.
 *
 *   - oil_types         : Mineral, Silicona, Vegetal Soya, Vegetal Girasol, ésteres…
 *   - transformer_types : Potencia, Distribución, Horno, Sumergible…
 *   - standards         : IEEE C57.104, IEC 60599… con su versión (trazabilidad)
 *   - tests             : las pruebas (cromas, fiquis, furanos…) con su peso para HI
 */
return new class extends Migration {
    public function up(): void
    {
        // ---- Tipos de aceite ----
        // Son módulos de negocio (CRUD + soft-delete + audit), no solo catálogo:
        // por eso traen slug/created_by/deleted_*. El slug lo auto-genera el modelo.
        Schema::create('oil_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->nullable()->unique();
            // Nullable: como módulo de negocio el code es opcional (form e import
            // lo dejan en null). En Postgres el UNIQUE admite varios NULL.
            $table->string('code', 40)->nullable()->unique(); // mineral, silicona…
            $table->string('name', 100);               // "Mineral", "Silicona"…
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- Tipos de transformador ----
        Schema::create('transformer_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->nullable()->unique();
            $table->string('code', 40)->nullable()->unique(); // potencia, distribucion, horno…
            $table->string('name', 100);
            $table->string('shape', 20)->default('tank'); // figura del dibujo: tank | pole | dry
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- Normas técnicas (para trazabilidad) ----
        Schema::create('standards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();      // ieee_c57_104, iec_60599…
            $table->string('name', 150);               // "IEEE C57.104"
            $table->string('version', 30)->nullable(); // "2019", "2015"
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- Pruebas de diagnóstico ----
        // `hi_weight` = peso de esta prueba en el Índice de Salud (HI).
        // `hi_enabled` = si entra al cálculo del HI (factor potencia hoy = false).
        Schema::create('tests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();      // cromas, fiquis, furanos, fpot…
            $table->string('name', 120);
            $table->integer('hi_weight')->default(0);  // peso en el índice de salud
            $table->boolean('hi_enabled')->default(false); // ¿entra al HI hoy?
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tests');
        Schema::dropIfExists('standards');
        Schema::dropIfExists('transformer_types');
        Schema::dropIfExists('oil_types');
    }
};
