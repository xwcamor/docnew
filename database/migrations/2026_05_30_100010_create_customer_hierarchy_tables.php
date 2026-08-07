<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jerarquía geográfica del cliente (del sistema viejo, ahora relacional):
 *
 *   Customer → Ubicación → Área → Subestación → Transformador
 *
 * Cada nivel es per-tenant, auditable y soft-deletable (patrón master template).
 * El transformador cuelga de una subestación (customer_substation_id), así el
 * "ver cliente" arma su parque completo como árbol.
 *
 * `customers.cod` ya es el identificador comercial (RUC); aquí solo se agrega
 * `address` (dirección), que la vista vieja mostraba.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_locations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_id', 'name'], 'cust_loc_customer_name_idx');
        });

        Schema::create('customer_areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('customer_location_id')->constrained('customer_locations')->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_location_id', 'name'], 'cust_area_loc_name_idx');
        });

        Schema::create('customer_substations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('customer_area_id')->constrained('customer_areas')->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['customer_area_id', 'name'], 'cust_sub_area_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_substations');
        Schema::dropIfExists('customer_areas');
        Schema::dropIfExists('customer_locations');
    }
};
