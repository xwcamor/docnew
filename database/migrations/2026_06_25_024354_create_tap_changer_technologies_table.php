<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tap_changer_technologies — tabla base generada por make:module.
 * Agregar columnas custom del dominio aquí.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tap_changer_technologies', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name')->index();
            $table->string('code')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_active')->default(true);

            // Audit + soft-delete (patrón master template).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
            // Performance indexes — listado + trash + filtros (patron Regions).
            $table->index(['is_active', 'created_at'], 'idx_tap_changer_technologies_tenant_active_created');
            $table->index('created_at', 'idx_tap_changer_technologies_created_at');
            $table->index('updated_at', 'idx_tap_changer_technologies_updated_at');
            $table->index('deleted_at', 'idx_tap_changer_technologies_deleted_at');
            $table->index('created_by', 'idx_tap_changer_technologies_created_by');
            $table->index('is_active',  'idx_tap_changer_technologies_is_active');
        });

        // Catálogo global (sin tenant): nombre único case/accent-insensitive.
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX tap_changer_technologies_name_unique_active " .
                "ON tap_changer_technologies (unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            // varchar_pattern_ops para `WHERE name LIKE 'X%'` eficiente.
            DB::statement('CREATE INDEX idx_tap_changer_technologies_name_pattern ON tap_changer_technologies (name varchar_pattern_ops)');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX tap_changer_technologies_name_unique_active " .
                "ON tap_changer_technologies (LOWER(name)) " .
                "WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tap_changer_technologies');
    }
};
