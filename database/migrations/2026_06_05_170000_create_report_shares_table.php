<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * report_shares — enlaces públicos para compartir diagnósticos con clientes
 * externos (sin cuenta), con expiración y acceso por OTP al correo.
 *
 * scope_type: 'transformer' (un trafo) | 'fleet' (todos los trafos del cliente).
 * El token va en la URL pública /r/{token}. Read-only; los datos se leen siempre
 * scoped al transformer_id/customer_id de este share (no se expone otro tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_shares', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('scope_type', 16); // transformer | fleet
            $table->unsignedBigInteger('transformer_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->json('transformer_ids')->nullable(); // flota parcial (subconjunto)
            $table->foreignId('tenant_id')->nullable()->index()
                ->constrained('tenants')->nullOnDelete();
            $table->string('recipient_email', 190);
            $table->string('locale', 5)->default('es');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_opened_at')->nullable();
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['transformer_id']);
            $table->index(['customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
