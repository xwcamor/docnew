<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * El flujo de aprobacion deja de estar clavado en tres firmas.
 *
 * Dos cosas lo limitaban:
 *
 *  1. `approval_rules` era por pais, asi que **todos los tipos de trabajo
 *     compartian el mismo flujo**. Un mantenimiento estandar y una maniobra de
 *     izaje de carga exigian las mismas tres firmas, y en seguridad no funciona
 *     asi: a mas riesgo, mas aprobaciones. Ahora `work_type_id` acota la regla,
 *     y en nulo sigue valiendo para todos.
 *
 *  2. Quien podia firmar salia de tres constantes en codigo
 *     (`PersonRole::WORKER|SUPERVISOR|HSE_SUPERVISOR`). Anadir un «Cliente» o un
 *     «Jefe de obra» era un despliegue. Ahora es un catalogo: `approver_roles`.
 *
 * Los niveles y lo obligatorio ya escalaban; esto completa lo que faltaba.
 */
return new class extends Migration
{
    /** Los tres que ya existian, para no perder nada al pasar a catalogo. */
    private const INICIALES = [
        ['code' => 'worker',         'name_es' => 'Trabajador',     'name_en' => 'Worker'],
        ['code' => 'supervisor',     'name_es' => 'Supervisor',     'name_en' => 'Supervisor'],
        ['code' => 'hse_supervisor', 'name_es' => 'Supervisor HSE', 'name_en' => 'HSE supervisor'],
    ];

    public function up(): void
    {
        Schema::create('approver_roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 22)->unique();
            $table->string('code', 30);
            $table->string('name_es', 60);
            $table->string('name_en', 60);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->text('deleted_description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('deleted_at', 'idx_approver_roles_deleted_at');
        });

        // Unico por codigo dentro del workspace, contando el global (tenant nulo).
        DB::statement("CREATE UNIQUE INDEX approver_roles_code_unique
            ON approver_roles (lower(code), coalesce(tenant_id, 0)) WHERE deleted_at IS NULL");

        foreach (self::INICIALES as $i => $rol) {
            DB::table('approver_roles')->insert($rol + [
                'slug' => Str::random(22), 'sort_order' => $i + 1,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('approval_rules', function (Blueprint $table) {
            // En nulo la regla vale para todos los tipos de trabajo, que es como
            // se comportaba hasta ahora: nada cambia hasta que alguien acote una.
            $table->foreignId('work_type_id')->nullable()->after('country_id')
                ->constrained('work_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('approval_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_type_id');
        });

        Schema::dropIfExists('approver_roles');
    }
};
