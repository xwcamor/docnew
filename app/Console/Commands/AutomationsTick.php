<?php

namespace App\Console\Commands;

use App\Jobs\Automations\RunAutomationJob;
use App\Models\Automation;
use Illuminate\Console\Command;

/**
 * automations:tick — el único cron que necesita el sistema de automatizaciones.
 *
 * Corre cada minuto. Busca automations activas con next_run_at vencido,
 * despacha un job por cada una al queue y reprograma next_run_at usando
 * el helper computeNextRunAt() del modelo.
 *
 * Diseño defensivo: si compute devuelve null (config inválido), la
 * automation queda pausada con next_run_at NULL hasta que el usuario la
 * corrija. No reintentamos automáticamente — el log queda en
 * automation_runs si llegó a despacharse el job.
 */
class AutomationsTick extends Command
{
    protected $signature = 'automations:tick';
    protected $description = 'Despacha automations cuyo next_run_at venció';

    public function handle(): int
    {
        $dispatched = 0;
        $skipped    = 0;

        Automation::where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->chunkById(50, function ($automations) use (&$dispatched, &$skipped) {
                foreach ($automations as $automation) {
                    // Reprogramar PRIMERO. Si compute devuelve null la marcamos
                    // como pausada (next_run_at = null) pero igual disparamos
                    // esta ejecución que ya tenía su turno.
                    $next = $automation->computeNextRunAt(now());
                    $automation->forceFill(['next_run_at' => $next])->save();

                    RunAutomationJob::dispatch($automation->id);
                    $dispatched++;

                    if (!$next) $skipped++;
                }
            });

        $this->info("Despachadas: {$dispatched}. Sin próximo run (config inválido): {$skipped}.");
        return self::SUCCESS;
    }
}
