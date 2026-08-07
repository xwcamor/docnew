<?php

namespace App\Console\Commands;

use App\Models\Transformer;
use App\Services\Reports\ReportChartCache;
use Illuminate\Console\Command;

/**
 * Purga el caché de gráficos de informes (storage/app/report-charts):
 *
 *   php artisan reports:purge-chart-cache
 *
 * Borra los archivos de trafos que ya no existen (huérfanos). El caché se
 * repuebla solo al generar/compartir el informe, así que purgar es seguro.
 * Pensado para correr periódicamente (scheduler) o a mano.
 */
class PurgeReportChartCache extends Command
{
    protected $signature = 'reports:purge-chart-cache';
    protected $description = 'Borra el caché de gráficos de informes de transformadores que ya no existen';

    public function handle(ReportChartCache $cache): int
    {
        $cachedIds = $cache->cachedIds();
        if (empty($cachedIds)) {
            $this->info('No hay caché de gráficos.');
            return self::SUCCESS;
        }

        // Trafos vivos O en papelera siguen siendo válidos; solo los borrados
        // definitivamente (ausentes incluso withTrashed) son huérfanos.
        $alive = Transformer::withTrashed()->whereIn('id', $cachedIds)->pluck('id')->all();
        $orphans = array_diff($cachedIds, $alive);

        foreach ($orphans as $id) {
            $cache->forget($id);
        }

        $this->info(sprintf('Caché: %d archivos · %d huérfanos purgados.', count($cachedIds), count($orphans)));
        return self::SUCCESS;
    }
}
