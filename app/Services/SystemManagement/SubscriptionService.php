<?php

namespace App\Services\SystemManagement;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SubscriptionService — orchestrates subscription lifecycle:
 *   - create()    → nueva sub (trial o pagada) para un tenant
 *   - extend()    → renovación (corta la sub actual + crea siguiente)
 *   - cancel()    → cancelación antes de ends_at
 *   - suspend()   → pausar (no pagó, dispute, etc.)
 *   - markExpired() → cron diario marca expired las que pasaron ends_at
 *
 * Cada método retorna la Subscription afectada. Validaciones de input quedan
 * en los FormRequests del controller — el service confía en su caller.
 */
class SubscriptionService
{
    /**
     * Crear una subscription nueva para un tenant. Si ya tiene una current,
     * el caller debería renovar (extend) en su lugar — pero aquí no bloqueamos
     * por si super necesita forzar una segunda (caso edge).
     */
    public function create(Tenant $tenant, array $data, ?User $createdBy = null): Subscription
    {
        $sub = Subscription::create([
            'tenant_id'      => $tenant->id,
            'plan'           => $data['plan'],
            'status'         => $data['status'] ?? Subscription::STATUS_ACTIVE,
            'starts_at'      => $data['starts_at'] ?? now(),
            'ends_at'        => $data['ends_at'],
            'trial_ends_at'  => $data['trial_ends_at'] ?? null,
            'amount_paid'    => $data['amount_paid'] ?? null,
            'currency'       => $data['currency'] ?? 'USD',
            'payment_method' => $data['payment_method'] ?? 'manual',
            'notes'          => $data['notes'] ?? null,
            'created_by'     => $createdBy?->id ?? auth()->id(),
        ]);

        // No hay `tenants.plan` que sincronizar — el plan del tenant se deriva
        // en vivo de su suscripción vigente.
        return $sub;
    }

    /**
     * Trial — período de prueba gratis. Sub con status=trial, ends_at = now() + days.
     * Cuando expira, el cron la marca expired; el tenant queda sin plan activo
     * hasta que pague.
     */
    public function startTrial(Tenant $tenant, string $plan, int $days, ?User $createdBy = null): Subscription
    {
        $endsAt = now()->addDays($days);

        return $this->create($tenant, [
            'plan'          => $plan,
            'status'        => Subscription::STATUS_TRIAL,
            'starts_at'     => now(),
            'ends_at'       => $endsAt,
            'trial_ends_at' => $endsAt,
            'amount_paid'   => 0,
        ], $createdBy);
    }

    /**
     * Renovar: corta la sub actual en now() (deja histórico intacto) y crea
     * una nueva a partir de ese punto. Transacción para que no quede a medias.
     *
     * Caller debe pasar `ends_at` de la nueva sub (calculado según plan elegido).
     */
    public function extend(Tenant $tenant, array $data, ?User $createdBy = null): Subscription
    {
        $previousPlan = $tenant->currentPlan();
        $newPlan      = $data['plan'] ?? $previousPlan;

        $newSub = DB::transaction(function () use ($tenant, $data, $createdBy) {
            $current = $tenant->activeSubscription;

            // Si hay sub activa, la cerramos en now() para preservar histórico.
            if ($current) {
                $current->update([
                    'status'  => Subscription::STATUS_EXPIRED,
                    'ends_at' => now(),  // corte en el punto de renovación
                ]);
            }

            return $this->create($tenant, array_merge($data, [
                'status'    => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
            ]), $createdBy);
        });

        // Notificar a los admins del tenant si el plan CAMBIÓ (no si es solo
        // una renovación del mismo plan). Best-effort: si falla la notif el
        // cambio de sub ya quedó persistido — solo loggeamos.
        if ($previousPlan !== $newPlan) {
            try {
                $this->notifyPlanChange($tenant, $previousPlan, $newPlan);
            } catch (\Throwable $e) {
                \Log::warning('SubscriptionService::extend notify failed', [
                    'tenant_id' => $tenant->id,
                    'previous'  => $previousPlan,
                    'new'       => $newPlan,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $newSub;
    }

    /**
     * Decide la dirección (upgrade/downgrade) usando el orden de planes del
     * config y dispara PlanChanged a los admins del tenant.
     */
    protected function notifyPlanChange(Tenant $tenant, string $previousPlan, string $newPlan): void
    {
        $order = config('features.plans', ['free', 'basic', 'pro', 'enterprise']);
        $prevIdx = array_search($previousPlan, $order, true);
        $newIdx  = array_search($newPlan, $order, true);

        $direction = 'change';
        if ($prevIdx !== false && $newIdx !== false) {
            $direction = $newIdx > $prevIdx ? 'upgrade' : ($newIdx < $prevIdx ? 'downgrade' : 'change');
        }

        // Solo notificamos a admins del tenant — no a workers ni system_users.
        $admins = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'api'))
            ->get()
            ->filter(fn ($u) => $u->hasRole('admin'));

        foreach ($admins as $admin) {
            $admin->notify(new \App\Notifications\PlanChanged(
                tenant:       $tenant,
                previousPlan: $previousPlan,
                newPlan:      $newPlan,
                direction:    $direction,
            ));
        }
    }

    /**
     * Cancelar — cancel SUAVE. El cliente conserva el acceso hasta `ends_at`
     * (la sub sigue siendo "current" mientras no venza — ver
     * Subscription::scopeCurrent()). NO tocamos `ends_at`: respetamos el
     * período ya pagado. Para cortar el acceso YA → usar suspend().
     */
    public function cancel(Subscription $sub, string $reason): Subscription
    {
        $sub->update([
            'status'              => Subscription::STATUS_CANCELLED,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ]);

        return $sub->fresh();
    }

    /**
     * Suspender — corte DURO, acceso cortado inmediatamente. Ej: el pago
     * falló y disputaron. `status=suspended` la saca del scope current();
     * `ends_at=now()` lo deja explícito en el registro. El tenant cae a
     * `free` derivado, y además EnforceSubscription lo bloquea por completo
     * vía Tenant::isSuspended().
     */
    public function suspend(Subscription $sub, string $reason): Subscription
    {
        $sub->update([
            'status'              => Subscription::STATUS_SUSPENDED,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
            'ends_at'             => now(),
        ]);

        return $sub->fresh();
    }

    /**
     * Marca como expired las subs que pasaron ends_at y siguen como trial/active.
     * Lo llama el cron diario. Retorna count para logging.
     *
     * Iteramos (en vez de un bulk UPDATE) para que cada expiración dispare el
     * trait Auditable — coherencia con cancel/suspend, que sí quedan auditados.
     * No hay snapshot `tenants.plan` que resincronizar: al cambiar el status,
     * la sub sale de scopeCurrent() y el tenant pasa a derivar `free`
     * automáticamente.
     */
    public function markExpired(): int
    {
        $count = 0;

        Subscription::justExpired()->each(function (Subscription $sub) use (&$count) {
            $sub->update(['status' => Subscription::STATUS_EXPIRED]);
            $count++;
        });

        return $count;
    }
}
