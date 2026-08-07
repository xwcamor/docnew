<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC APP — planes de trabajo (la tarea del dia).
 *
 * Se llama work_plans y no plans porque en este SaaS `plans` ya son los planes
 * de suscripcion.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('work_type_id')->constrained('work_types')->restrictOnDelete();
            $table->foreignId('work_location_id')->constrained('work_locations')->restrictOnDelete();
            $table->foreignId('workstation_id')->nullable()->constrained('workstations')->nullOnDelete();
            $table->foreignId('work_area_id')->nullable()->constrained('work_areas')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->comment('Quien lo registra');
            $table->string('code')->index();
            $table->string('num_os')->nullable();
            $table->text('description');
            $table->date('date_start')->nullable();
            $table->date('date_end')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_done')->default(false);
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_work_plans_deleted_at');
            $table->index('created_at', 'idx_work_plans_created_at');
            $table->index(['company_id', 'created_at'], 'idx_work_plans_company_created');
            $table->index(['is_done', 'created_at'], 'idx_work_plans_done_created');
        });

        // Trabajadores asignados. Sin columnas de foto ni firma: la evidencia
        // vive en signature_events, que es la unica fuente de verdad.
        Schema::create('work_plan_people', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('work_plan_id')->constrained('work_plans')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->boolean('is_approved')->default(false)->comment('Lo calcula el servidor');
            $table->timestamps();
            $table->unique(['work_plan_id', 'person_id'], 'work_plan_people_unique');
        });

        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('approver_role', 30)->comment('worker, supervisor, hse_supervisor');
            $table->unsignedSmallInteger('priority_level');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_approval_rules_deleted_at');
            $table->index('created_at', 'idx_approval_rules_created_at');
        });

        Schema::create('work_plan_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->foreignId('work_plan_id')->constrained('work_plans')->cascadeOnDelete();
            $table->foreignId('approval_rule_id')->constrained('approval_rules')->restrictOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_approved')->default(false)->comment('Lo calcula el servidor');
            $table->timestamps();
            $table->unique(['work_plan_id', 'approval_rule_id'], 'work_plan_approvals_unique');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX work_plans_code_unique_active " .
                "ON work_plans (tenant_id, country_id, UPPER(code)) WHERE deleted_at IS NULL"
            );
            DB::statement(
                "CREATE UNIQUE INDEX approval_rules_unique_active " .
                "ON approval_rules (tenant_id, country_id, approver_role, priority_level) WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_plan_approvals');
        Schema::dropIfExists('approval_rules');
        Schema::dropIfExists('work_plan_people');
        Schema::dropIfExists('work_plans');
    }
};
