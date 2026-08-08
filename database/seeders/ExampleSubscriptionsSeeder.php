<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * ExampleSubscriptionsSeeder — suscripciones del demo base.
 *
 * Da a cada workspace de ejemplo un plan distinto, para que el dev pruebe el
 * sistema con varios tiers a la vez:
 *
 *   Empresa 1  (joe)  → enterprise  — todo desbloqueado
 *
 * El tier `free` lo cubre el workspace "Estudio Pérez" (ExamplePersonalWorkspaceSeeder),
 * que justamente NO tiene suscripción — eso ES estar en free.
 *
 * El plan se deriva de la suscripción vigente; NO hay columna `tenants.plan`.
 *
 * Idempotente y canónico: borra las suscripciones previas del tenant
 * y recrea el estado intencional. Re-correrlo siempre deja el demo base limpio.
 */
class ExampleSubscriptionsSeeder extends Seeder
{
    public function run(): void
    {
        // tenant_id => plan pago.
        $paidAssignments = [
            1 => 'enterprise',
        ];

        $demoTenantIds = [1];

        // Limpieza: dejamos el demo base en su estado canónico. Estas son
        // suscripciones de ejemplo, no histórico real — hard delete está bien.
        Subscription::withTrashed()
            ->whereIn('tenant_id', $demoTenantIds)
            ->forceDelete();

        foreach ($paidAssignments as $tenantId => $planSlug) {
            $tenant = Tenant::find($tenantId);
            if (!$tenant) {
                $this->command?->warn("Tenant id={$tenantId} no existe — skip.");
                continue;
            }

            $plan = Plan::findBySlug($planSlug);
            $amount = $plan ? (float) $plan->price_yearly : 0;

            Subscription::create([
                'tenant_id'      => $tenantId,
                'plan'           => $planSlug,
                'status'         => Subscription::STATUS_ACTIVE,
                'starts_at'      => now()->subMonth(),
                'ends_at'        => now()->subMonth()->addYear(),
                'trial_ends_at'  => null,
                'amount_paid'    => $amount,
                'currency'       => 'USD',
                'payment_method' => 'manual',
                'notes'          => 'Suscripción del demo base (ExampleSubscriptionsSeeder).',
                'created_by'     => null,
            ]);

            $this->command?->info("Tenant {$tenant->name}: suscripción '{$planSlug}' activa por 1 año.");
        }
    }
}
