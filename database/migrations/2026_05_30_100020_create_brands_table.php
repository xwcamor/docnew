<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * brands — tabla base generada por make:module.
 * Agregar columnas custom del dominio aquí.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name')->index();
            $table->string('code')->nullable();
            $table->integer('sort_order')->nullable();
            $table->boolean('is_active')->default(true);

            // Catálogo per-tenant: cada workspace tiene su propio catálogo de marcas.
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();

            // Audit + soft-delete (patrón master template).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();
            // Performance indexes — listado + trash + filtros (patron Regions).
            $table->index(['is_active', 'created_at'], 'idx_brands_tenant_active_created');
            $table->index('created_at', 'idx_brands_created_at');
            $table->index('updated_at', 'idx_brands_updated_at');
            $table->index('deleted_at', 'idx_brands_deleted_at');
            $table->index('created_by', 'idx_brands_created_by');
            $table->index('is_active',  'idx_brands_is_active');
        });

        // Catálogo per-tenant: nombre único por tenant, case/accent-insensitive.
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX brands_name_unique_active " .
                "ON brands (tenant_id, unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            // varchar_pattern_ops para `WHERE name LIKE 'X%'` eficiente.
            DB::statement('CREATE INDEX idx_brands_name_pattern ON brands (name varchar_pattern_ops)');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX brands_name_unique_active " .
                "ON brands (tenant_id, LOWER(name)) " .
                "WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
