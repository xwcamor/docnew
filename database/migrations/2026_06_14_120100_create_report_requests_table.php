<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitud de aprobación (etapa 2 de firmas) — la UNIDAD que se aprueba.
 *
 * Agrupa N informes (report_instances): 1 si es un trafo suelto, N si es una
 * flota. Los firmantes aprueban la SOLICITUD una sola vez → se aprueban todos
 * sus informes de golpe (no 40 aprobaciones, una). Si la solicitud nació de un
 * "compartir", guarda los datos del envío (destinatario/expiración/idioma) y al
 * aprobarse activa el share y manda el enlace solo (auto-compartir).
 *
 * Estados: in_review | approved | rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('requester_id');           // quién envió a aprobación
            $table->string('scope', 12)->default('transformer');  // transformer | fleet
            $table->unsignedBigInteger('customer_id')->nullable(); // contexto flota
            $table->string('label', 160)->nullable();             // "Flota INLAND — 40 informes"
            $table->string('status', 12)->default('in_review')->index();

            // Auto-compartir: si la solicitud nació de un share, estos datos
            // disparan el envío al aprobarse. report_share_id = share ya activado.
            $table->string('recipient_email', 190)->nullable();
            $table->unsignedSmallInteger('expiration_days')->nullable();
            $table->string('locale', 5)->nullable();
            $table->unsignedBigInteger('report_share_id')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_requests');
    }
};
