<?php

namespace App\Console\Commands;

use App\Models\Transformer;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Console\Command;

/**
 * Backfill de la caché de diagnóstico de flota (fault_type, gassing_rate,
 * paper_dp, ieee_condition) de TODOS los transformadores. Reevalúa el HI con
 * persist (que ahora también escribe esas columnas) y SIN notificar, para no
 * inundar la campana al recalcular en masa.
 *
 *   php artisan diagnose:fleet-cache
 */
class DiagnoseFleetCacheCommand extends Command
{
    protected $signature = 'diagnose:fleet-cache {--chunk=200 : Tamaño de lote}';
    protected $description = 'Recalcula la caché de diagnóstico de flota de todos los transformadores';

    public function handle(HealthIndexService $hi): int
    {
        $total = Transformer::withoutGlobalScopes()->count();
        if ($total === 0) {
            $this->info('No hay transformadores.');
            return self::SUCCESS;
        }

        $this->info("Recalculando caché de flota de {$total} transformadores…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Transformer::withoutGlobalScopes()
            ->with(['oilType', 'transformerType'])
            ->chunkById((int) $this->option('chunk'), function ($chunk) use ($hi, $bar) {
                foreach ($chunk as $t) {
                    $hi->evaluate($t, persist: true, notify: false);
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info('Listo.');
        return self::SUCCESS;
    }
}
