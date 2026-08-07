<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instancia de informe oficial bajo flujo de aprobación (etapa 2 de firmas).
 *
 * Solo se crea cuando el workspace tiene `reports.require_approval = true` y el
 * preparador "envía a aprobación". Congela un SNAPSHOT de los datos del informe
 * (JSON) para que los firmantes aprueben exactamente eso. Estados:
 *   in_review  → enviado, esperando firmas
 *   approved   → todos firmaron → oficial/emitido (issued_at seteado)
 *   rejected   → algún firmante rechazó (vuelve al preparador)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('transformer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('preparer_id')->nullable()->index(); // user que preparó
            $table->string('status', 20)->default('in_review')->index();    // in_review|approved|rejected
            $table->string('report_code', 120)->nullable();
            $table->string('verify_code', 64)->nullable()->index();
            $table->json('snapshot')->nullable();        // datos del informe congelados
            $table->timestamp('issued_at')->nullable();  // cuándo quedó oficial (approved)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_instances');
    }
};
