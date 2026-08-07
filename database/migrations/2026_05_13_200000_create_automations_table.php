<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * automations — reglas de automatización editables por el tenant.
 *
 * Una automation define: cuándo ejecutar (trigger), qué datos consultar
 * (data_source + data_filter) y qué acción tomar (action_type + action_config).
 *
 * El comando `automations:tick` corre cada minuto, busca filas con
 * next_run_at <= now() AND is_active = true, y despacha un job por cada
 * una. El job ejecuta y registra el resultado en automation_runs.
 *
 * Por defecto cada automation pertenece a un tenant (multi-tenant scoping
 * via BelongsToTenant trait). Si tenant_id es NULL, es del sistema
 * (solo super).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            // tenant_id con FK constrained — si super hard-deletea el tenant,
            // las automations quedan con tenant_id=NULL (no se cascade-borran
            // ni bloquean el delete). Patrón coherente con roles/customers.
            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            // Trigger: schedule | event | webhook (v1 solo schedule).
            $table->string('trigger_type', 30)->default('schedule');
            $table->json('trigger_config')->nullable();
            // Ejemplo: { "kind": "daily", "time": "09:00", "timezone": "UTC" }
            //   o     { "kind": "cron",  "expression": "0 9 * * *" }

            // Data source opcional: si la action necesita datos, este campo
            // dice de qué módulo sacarlos. NULL = action sin datos previos.
            $table->string('data_source', 60)->nullable();
            $table->json('data_filter')->nullable();
            // Ejemplo: { "where": [{ "field": "status", "op": "=", "value": "pending" }], "limit": 100 }

            // Action: email | in_app_notification | webhook (v1 solo los dos primeros).
            $table->string('action_type', 30);
            $table->json('action_config')->nullable();
            // Ejemplo email: { "to": ["admin@x.com"], "subject": "...", "body": "..." }

            // Ejecución
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedInteger('failures_count')->default(0);

            // Audit + soft-delete (patrón master template).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Index compuesto crítico: el cron tick filtra por estos 3.
            $table->index(['is_active', 'next_run_at'], 'idx_auto_active_next');

            // Performance indexes — listado + trash + filtros (patron Regions).
            $table->index(['tenant_id', 'is_active', 'created_at'], 'idx_automations_tenant_active_created');
            $table->index('created_at',  'idx_automations_created_at');
            $table->index('updated_at',  'idx_automations_updated_at');
            $table->index('deleted_at',  'idx_automations_deleted_at');
            $table->index('created_by',  'idx_automations_created_by');
            $table->index('is_active',   'idx_automations_is_active');
            // Filtros desde la UI — data_source y action_type son selects.
            $table->index('data_source', 'idx_automations_data_source');
            $table->index('action_type', 'idx_automations_action_type');
        });

        // Partial unique unaccent + pattern_ops — solo Postgres.
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement(
                "CREATE UNIQUE INDEX automations_tenant_name_unique_active " .
                "ON automations (COALESCE(tenant_id, 0), unaccent_immutable(LOWER(name))) " .
                "WHERE deleted_at IS NULL"
            );
            DB::statement('CREATE INDEX idx_automations_name_pattern ON automations (name varchar_pattern_ops)');
        } elseif ($driver === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX automations_tenant_name_unique_active " .
                "ON automations (COALESCE(tenant_id, 0), LOWER(name)) " .
                "WHERE deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
