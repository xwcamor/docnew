<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firmantes de informes por workspace (N slots, escalable).
 *
 * Reemplaza al aprobador único: cada workspace define SU flujo de firmas
 * (Supervisor, Auditor, Jefe de Laboratorio, …) como filas de DATOS. Cada
 * slot sale en el bloque de firmas del PDF con su cargo; la firma se estampa
 * SOLO si el usuario asignado tiene firma cargada Y activó auto-firma desde
 * su perfil (consentimiento auditado). Firmantes externos (sin usuario) van
 * por nombre y firman a mano.
 *
 * Migra el aprobador único existente (tenants.report_approver_user_id /
 * report_approver) como primer slot. Las columnas legacy quedan en tenants
 * (no se borran datos) pero la UI y el PDF usan solo esta tabla.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_signers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index(); // null = firmante externo
            $table->string('name', 120)->nullable();   // nombre impreso si es externo
            $table->string('title', 120);              // cargo impreso: Supervisor, Auditor…
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();
        });

        // Conversión del aprobador único actual → primer slot.
        foreach (DB::table('tenants')
            ->where(fn ($q) => $q->whereNotNull('report_approver_user_id')->orWhereNotNull('report_approver'))
            ->get(['id', 'report_approver', 'report_approver_user_id']) as $t) {
            DB::table('report_signers')->insert([
                'tenant_id'  => $t->id,
                'user_id'    => $t->report_approver_user_id,
                'name'       => $t->report_approver_user_id ? null : $t->report_approver,
                'title'      => 'Revisado / Aprobado',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('report_signers');
    }
};
