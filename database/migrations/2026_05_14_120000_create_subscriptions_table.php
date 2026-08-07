<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions — historial de planes por tenant.
 *
 * Una fila por período de suscripción (no se actualiza en lugar; cada cambio
 * = fila nueva con `ends_at` cortado en la anterior). Esto da:
 *   - Histórico completo para reportes ("estuvo X meses en pro")
 *   - Trial periods separados del plan pagado
 *   - Auditoría natural ("¿quién renovó?") via trait Auditable
 *   - Migración trivial a Stripe/Cashier en el futuro
 *
 * El `plan` queda como string (no FK a una `plans` table) — KISS hasta que
 * necesites features-per-plan / precios variables / etc. en una sola tabla.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Plan name (free|pro|enterprise — debe coincidir con tenants.plan).
            $table->string('plan', 60);

            // Status: trial → active → expired/cancelled/suspended.
            //   trial:     período de prueba, acceso completo pero acotado
            //   active:    pagado y vigente
            //   expired:   pasó ends_at sin renovar (auto-marca via cron)
            //   suspended: pausada manualmente (no pagó, etc.)
            //   cancelled: cancelada antes de ends_at
            $table->string('status', 20)->default('trial');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('trial_ends_at')->nullable();   // Solo si status=trial
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            // Detalles del pago (manual hoy, hooks para Stripe/Paddle después).
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method', 40)->nullable();  // manual|bank_transfer|stripe|paddle

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            // Index para queries comunes:
            //   - "sub activa de este tenant" → (tenant_id, status, ends_at)
            //   - "subs que expiran pronto" → (status, ends_at)
            $table->index(['tenant_id', 'status'], 'idx_subs_tenant_status');
            $table->index(['status', 'ends_at'], 'idx_subs_status_ends');
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
