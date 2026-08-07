<?php

namespace App\Console\Commands;

use App\Notifications\AutomationTriggered;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Purga notificaciones in-app generadas por automatizaciones tras 12 horas
 * de creadas. Razón: las notifs de automation son info ambient — no requieren
 * ack del usuario y se autoborran para que el bell del header no se llene.
 *
 * Otras categorías (security/PasswordChanged, plan_change, etc.) NO se tocan;
 * esas sí requieren acción del usuario y deben quedarse hasta que las borre
 * a mano.
 *
 * Schedule: cada hora (routes/console.php → schedule('hourly')).
 *
 * Modos:
 *   php artisan automations:purge-old-notifications
 *   php artisan automations:purge-old-notifications --dry-run
 */
class PurgeAutomationNotifications extends Command
{
    protected $signature = 'automations:purge-old-notifications {--dry-run : Solo reporta, no borra}';

    protected $description = 'Borra notificaciones in-app de automatizaciones con mas de 12 horas';

    public function handle(): int
    {
        $cutoff = now()->subHours(12);
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('notifications')
            ->where('type', AutomationTriggered::class)
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No hay notificaciones de automation para purgar.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[dry-run] Se purgarian {$count} notificaciones (created_at < {$cutoff}).");
            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("Purgadas {$deleted} notificaciones de automation (created_at < {$cutoff}).");

        return self::SUCCESS;
    }
}
