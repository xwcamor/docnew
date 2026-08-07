<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transformer_preservations — catálogo global de sistemas de preservación del
 * aceite (conservador con membrana, tanque sellado con nitrógeno, respiración
 * libre, …). Metadato descriptivo, NO eje de diagnóstico. Sin módulo CRUD por
 * ahora: se gestiona por seed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('transformer_preservations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name')->index();
            $table->string('code')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('is_active', 'idx_transformer_preservations_is_active');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX transformer_preservations_name_unique_active " .
                "ON transformer_preservations (unaccent_immutable(LOWER(name))) WHERE deleted_at IS NULL"
            );
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX transformer_preservations_name_unique_active " .
                "ON transformer_preservations (LOWER(name)) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transformer_preservations');
    }
};
