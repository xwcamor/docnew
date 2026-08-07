<?php

namespace App\Console\Commands;

use App\Mail\SubscriptionExpiringSoon;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SystemManagement\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pasos del cron diario:
 *   1. markExpired() — pasa a `expired` toda sub con `ends_at <= now()` y status
 *      en (trial, active). Esto libera el bloqueo del middleware EnforceSubscription
 *      automáticamente para tenants que no renovaron.
 *   2. Para cada sub que vence en ≤ 7 días → manda email al admin del tenant.
 *      Idempotente vía cache de día (no manda 2 emails el mismo día por la
 *      misma sub).
 *
 * Schedule: bootstrap/app.php → withSchedule()->command('subscriptions:check')->daily()
 */
class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:check-expirations
                            {--dry-run : List subs without mutating data ni mandar mails}';

    protected $description = 'Mark expired subscriptions + email tenant admins of subs expiring in ≤ 7 days';

    public function handle(SubscriptionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Paso 1: marcar como expired las pasadas.
        $expiredCount = $dryRun
            ? Subscription::justExpired()->count()
            : $service->markExpired();

        $this->info($dryRun
            ? "[dry-run] Would mark {$expiredCount} subscriptions as expired."
            : "Marked {$expiredCount} subscriptions as expired.");

        // Paso 2: warning emails — subs vigentes con ≤ 7 días por delante.
        $expiringSoon = Subscription::expiringIn(7)
            ->with('tenant')
            ->get();

        // Setting global: si esta off, NO se envia ningun email aunque haya
        // subs por vencer. El paso 1 (marcar expired) corre igual.
        $emailEnabled = Setting::getBool('notifications.email_enabled', true);
        if (!$emailEnabled) {
            $this->warn("Setting notifications.email_enabled = false → no se enviaran warning emails.");
        }

        $emailsSent = 0;
        foreach ($expiringSoon as $sub) {
            if (!$sub->tenant) continue;

            // Buscar admin del tenant para notificar. Si hay varios, el primero.
            $admin = User::withoutGlobalScopes()
                ->where('tenant_id', $sub->tenant_id)
                ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
                ->where('is_active', true)
                ->first();

            if (!$admin) {
                $this->warn("Tenant {$sub->tenant->name} (id={$sub->tenant_id}) — sin admin para notificar, skip.");
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Would email {$admin->email} → sub #{$sub->id} ({$sub->plan}, {$sub->daysRemaining()} days left)");
                continue;
            }

            // Respeta el setting global de notifications.email_enabled.
            if (!$emailEnabled) continue;

            try {
                Mail::to($admin->email)->send(new SubscriptionExpiringSoon($sub, $admin));
                $emailsSent++;
            } catch (\Throwable $e) {
                Log::warning('Subscription expiration email failed', [
                    'subscription_id' => $sub->id,
                    'admin_email'     => $admin->email,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        $this->info($dryRun
            ? "[dry-run] Would send {$expiringSoon->count()} warning emails."
            : "Sent {$emailsSent} warning emails ({$expiringSoon->count()} subs expiring in ≤ 7 days).");

        return Command::SUCCESS;
    }
}
