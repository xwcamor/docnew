<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aprobación por firmante de una instancia de informe (etapa 2 de firmas).
 *
 * Una fila por cada slot de firmante INTERNO (usuario) que debe aprobar. Los
 * firmantes externos (sin usuario) no entran al flujo: salen como línea a mano.
 * La firma se estampa AQUÍ al aprobar = consentimiento puntual auditado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_instance_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('report_signer_id')->nullable()->index(); // slot de firmante
            $table->unsignedBigInteger('user_id')->index();   // quién debe/aprobó
            $table->string('title', 120);                     // cargo congelado al enviar
            $table->string('status', 12)->default('pending')->index(); // pending|approved|rejected
            $table->longText('signature')->nullable();        // firma estampada al aprobar (data uri)
            $table->string('reason', 500)->nullable();        // motivo si rechaza
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_approvals');
    }
};
