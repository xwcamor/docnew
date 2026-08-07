<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * customers — tabla base generada por make:module.
 * Agregar columnas custom del dominio aquí.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('name')->index();

            // `cod` representa el identificador comercial del cliente: RUC,
            // RFC, CUIT, código interno, etc. Genérico para cualquier país.
            $table->string('cod', 50)->nullable()->index();
            $table->string('address', 255)->nullable(); // dirección (vista vieja)
            $table->string('logo')->nullable();         // logo del cliente (informes)

            // País del cliente. FK con nullOnDelete: si se borra el country,
            // el customer queda sin país pero no se borra en cascada.
            $table->unsignedBigInteger('country_id')->nullable();

            $table->boolean('is_active')->default(true);
            // tenant_id con FK constrained — si super hard-deletea el tenant,
            // los customers quedan con tenant_id=NULL (no se cascade-borran ni
            // bloquean el delete). Patrón coherente con roles.tenant_id.
            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();

            // Audit + soft-delete (patrón master template).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('country_id')
                ->references('id')->on('countries')
                ->nullOnDelete();

            // Unicidad de cod por tenant + PAÍS (partial, se agrega abajo con
            // raw SQL para excluir soft-deleted).

            // Performance indexes — listado + trash + filtros (patron Regions).
            $table->index(['tenant_id', 'is_active', 'created_at'], 'idx_customers_tenant_active_created');
            $table->index('created_at', 'idx_customers_created_at');
            $table->index('updated_at', 'idx_customers_updated_at');
            $table->index('deleted_at', 'idx_customers_deleted_at');
            $table->index('created_by', 'idx_customers_created_by');
            $table->index('is_active',  'idx_customers_is_active');
        });

        // Unicidad de `cod` por (tenant, país) — partial (ignora soft-deleted).
        $driver = DB::getDriverName();
        if ($driver === 'pgsql' || $driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX customers_tenant_country_cod_unique ' .
                'ON customers (tenant_id, country_id, cod) WHERE deleted_at IS NULL'
            );
        } else {
            Schema::table('customers', fn (Blueprint $t) => $t->unique(['tenant_id', 'country_id', 'cod'], 'customers_tenant_country_cod_unique'));
        }

        // Partial unique unaccent + pattern_ops — solo Postgres.
        if ($driver === 'pgsql') {
            // Unique de name por tenant (NULL = sistema, sin colision con uno real).
            DB::statement(
                "CREATE UNIQUE INDEX customers_tenant_name_unique_active " .
                "ON customers (COALESCE(tenant_id, 0), unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            // varchar_pattern_ops para `WHERE name LIKE 'X%'` eficiente.
            DB::statement('CREATE INDEX idx_customers_name_pattern ON customers (name varchar_pattern_ops)');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX customers_tenant_name_unique_active " .
                "ON customers (COALESCE(tenant_id, 0), LOWER(name)) " .
                "WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
