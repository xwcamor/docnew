<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la migracion de planes, documentos y evidencias.
 *
 * Cada fila migrada tiene que poder volver a su origen en la v1, y el comando
 * tiene que poder correrse dos veces sin duplicar nada. `work_plans` ya traia
 * su `legacy_id`; aqui se lo damos al resto de tablas que se llenan desde el
 * sistema anterior.
 *
 * En `signature_events` no basta con el id: las firmas de la v1 salen de tres
 * sitios distintos (la tabla de eventos, la columna de firma del trabajador y
 * la de la aprobacion) y los ids se repiten entre ellos, asi que la identidad
 * es el par (tabla de origen, id de origen).
 */
return new class extends Migration {
    /** Tablas que solo necesitan recordar de que fila salieron. */
    protected array $simples = [
        'work_types', 'work_locations', 'workstations', 'work_areas',
        'approval_rules', 'work_plan_people', 'work_plan_approvals',
    ];

    public function up(): void
    {
        foreach ($this->simples as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->unsignedBigInteger('legacy_id')->nullable();
                $table->index('legacy_id', "idx_{$tabla}_legacy_id");
            });
        }

        // La entrega recuerda ademas de que tabla de formato salio (f1..f4),
        // porque los ids se repiten entre ellas.
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->string('legacy_table', 40)->nullable();
            $table->index(['legacy_table', 'legacy_id'], 'idx_form_submissions_legacy');
        });

        // Unico, no indice a secas: es lo que permite reescribir la fila en vez
        // de duplicarla cuando el comando se vuelve a correr. Los nulos no
        // chocan entre si, asi que las filas que no vienen de la v1 conviven.
        Schema::table('signature_events', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->string('legacy_source', 40)->nullable()
                ->comment('worker_signature_events, plan_workers, plan_approvals');
            $table->unique(['legacy_source', 'legacy_id'], 'signature_events_legacy_unique');
        });

        Schema::table('work_plans', function (Blueprint $table) {
            $table->unique('legacy_id', 'work_plans_legacy_id_unique');
        });
    }

    public function down(): void
    {
        foreach ($this->simples as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->dropIndex("idx_{$tabla}_legacy_id");
                $table->dropColumn('legacy_id');
            });
        }

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropIndex('idx_form_submissions_legacy');
            $table->dropColumn(['legacy_id', 'legacy_table']);
        });

        Schema::table('signature_events', function (Blueprint $table) {
            $table->dropUnique('signature_events_legacy_unique');
            $table->dropColumn(['legacy_id', 'legacy_source']);
        });

        Schema::table('work_plans', function (Blueprint $table) {
            $table->dropUnique('work_plans_legacy_id_unique');
        });
    }
};
