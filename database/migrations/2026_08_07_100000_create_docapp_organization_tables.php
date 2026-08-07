<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC APP — organizacion: empresas contratistas, sedes y catalogos de obra.
 *
 * Sustituye a las tablas companies/areas/locations/workstations/positions/
 * nationalities/jobs/work_types del sistema v1 (Rails), con las convenciones
 * de este SaaS: slug de 22, tenant_id, auditoria y borrado logico.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('num_doc')->index();
            $table->string('name')->index();
            $table->string('complete_name');
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_companies_deleted_at');
            $table->index('created_at', 'idx_companies_created_at');
        });

        Schema::create('nationalities', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_nationalities_deleted_at');
            $table->index('created_at', 'idx_nationalities_created_at');
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('code')->nullable();
            // Un cargo puede firmar como aprobador del plan.
            $table->boolean('is_signature_approver')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_positions_deleted_at');
            $table->index('created_at', 'idx_positions_created_at');
        });

        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('name')->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_work_locations_deleted_at');
            $table->index('created_at', 'idx_work_locations_created_at');
        });

        Schema::create('workstations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('work_location_id')->constrained('work_locations')->restrictOnDelete();
            $table->string('name')->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_workstations_deleted_at');
            $table->index('created_at', 'idx_workstations_created_at');
        });

        Schema::create('work_areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('name')->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_work_areas_deleted_at');
            $table->index('created_at', 'idx_work_areas_created_at');
        });

        Schema::create('work_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('code')->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_work_types_deleted_at');
            $table->index('created_at', 'idx_work_types_created_at');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            // El RUC no se repite dentro del mismo pais y tenant.
            DB::statement(
                "CREATE UNIQUE INDEX companies_num_doc_unique_active " .
                "ON companies (tenant_id, country_id, num_doc) WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX work_types_code_unique_active " .
                "ON work_types (tenant_id, country_id, unaccent_immutable(LOWER(code))) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_types');
        Schema::dropIfExists('work_areas');
        Schema::dropIfExists('workstations');
        Schema::dropIfExists('work_locations');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('nationalities');
        Schema::dropIfExists('companies');
    }
};
