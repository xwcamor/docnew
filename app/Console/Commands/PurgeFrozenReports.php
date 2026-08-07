<?php

namespace App\Console\Commands;

use App\Models\ReportInstance;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Purga por retención los ARCHIVOS de informes congelados más viejos que
 * `reports.frozen_retention_years` (setting editable por super; 0 = nunca).
 *
 * Solo se borra el archivo físico: el registro conserva el snapshot de datos
 * (muestras que respaldaron la firma) y la huella sha256 — la auditoría de qué
 * se firmó sobrevive a la purga. `frozen_path` queda null (las descargas caen
 * al render en vivo, marcable como re-emisión histórica).
 */
class PurgeFrozenReports extends Command
{
    protected $signature = 'reports:purge-frozen {--dry-run : Solo listar, sin borrar}';

    protected $description = 'Purga los archivos de informes congelados según la retención configurada (conserva snapshot y hash)';

    public function handle(): int
    {
        $years = (int) Setting::get('reports.frozen_retention_years', 2);
        if ($years <= 0) {
            $this->info('Retención 0 = nunca purgar. Nada que hacer.');

            return self::SUCCESS;
        }

        $cutoff = now()->subYears($years);
        $stale = ReportInstance::withoutGlobalScopes()
            ->whereNotNull('frozen_path')
            ->where('frozen_at', '<', $cutoff)
            ->get();

        $purged = 0;
        foreach ($stale as $instance) {
            if ($this->option('dry-run')) {
                $this->line("[dry-run] {$instance->frozen_path} (congelado {$instance->frozen_at})");
                continue;
            }
            Storage::disk('local')->delete($instance->frozen_path);
            // saveQuietly: la purga es mantenimiento, no un cambio auditable del registro.
            $instance->forceFill(['frozen_path' => null])->saveQuietly();
            $purged++;
        }

        $this->info("Purgados: {$purged} de {$stale->count()} candidatos (retención: {$years} años).");

        return self::SUCCESS;
    }
}
