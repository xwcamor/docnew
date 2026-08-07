<?php

namespace App\Console\Commands;

use App\Models\Download;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * CleanupExpiredDownloads — borra los archivos físicos de exports cuyo row
 * `downloads` ya expiró (expires_at < now()) o cuyo download fue marcado
 * como `downloaded` hace más de N horas.
 *
 * Schedule: corre cada hora (ver routes/console.php). En producción cubre 3
 * casos típicos:
 *   - Export ready que nunca se descargó → expira a las 24h, archivo se
 *     borra del disco y el row queda solo como audit.
 *   - Export ya descargado → se limpia tras `downloaded_grace_hours` (24h)
 *     para no acumular MBs en `storage/app/downloads/`.
 *   - Export failed → archivo nunca se generó (no hay path), solo se borra el row.
 *
 * Diseñado para correr en loop sin nunca crashear: cada Download es un
 * try/catch independiente. Si un archivo no existe, no es error — solo log.
 *
 * Modos:
 *   php artisan app:cleanup-expired-downloads              # ejecuta
 *   php artisan app:cleanup-expired-downloads --dry-run    # reporta sin tocar
 */
class CleanupExpiredDownloads extends Command
{
    protected $signature = 'app:cleanup-expired-downloads
        {--dry-run : Solo reporta qué se borraría, no toca nada.}
        {--grace= : Horas tras `downloaded_at` para borrar el archivo (default 24).}';

    protected $description = 'Borra archivos físicos de exports expirados o ya descargados (>24h)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        // Grace = horas tras descarga antes de borrar el archivo. La opción
        // --grace tiene prioridad; si no se pasa, lee de Settings (editable
        // desde la UI sin redeploy), con fallback a 24.
        $grace  = $this->option('grace') !== null
            ? (int) $this->option('grace')
            : Setting::getInt('downloads.grace_hours', 24);

        $expiredCutoff    = now();
        $downloadedCutoff = now()->subHours($grace);

        $filesDeleted   = 0;
        $rowsDeleted    = 0;
        $staleFailed    = 0;
        $errors         = 0;

        // 0. Downloads atascados en 'processing' más allá de un umbral seguro
        //    (muy por encima del timeout del job de 10 min). Pasa cuando el
        //    worker murió a mitad (OOM/kill) o simplemente NO hay queue:work
        //    corriendo: el job nunca termina y el row queda "procesando" para
        //    siempre. Los marcamos 'failed' con un mensaje accionable (el
        //    observer del modelo avisa in-app) para que el usuario no quede
        //    esperando un archivo que no va a llegar.
        $staleMinutes = Setting::getInt('downloads.stale_processing_minutes', 30);
        $staleCutoff  = now()->subMinutes($staleMinutes);
        Download::where('status', 'processing')
            ->where('created_at', '<', $staleCutoff)
            ->chunkById(500, function ($downloads) use ($dryRun, &$staleFailed) {
                foreach ($downloads as $d) {
                    if (!$dryRun) {
                        $d->update([
                            'status'        => 'failed',
                            'error_message' => __('global.download_stalled'),
                        ]);
                    }
                    $staleFailed++;
                }
            });
        if ($staleFailed > 0) {
            $this->line("  <fg=yellow>stale</> {$staleFailed} download(s) en 'processing' > {$staleMinutes} min → failed");
        }

        // 1. Downloads con expires_at < now() — expiraron sin descargarse o
        //    quedaron flotando. Borramos archivo + row.
        Download::where('expires_at', '<', $expiredCutoff)
            ->chunkById(500, function ($downloads) use ($dryRun, &$filesDeleted, &$rowsDeleted, &$errors) {
                foreach ($downloads as $d) {
                    try {
                        if ($d->path && Storage::disk($d->disk)->exists($d->path)) {
                            if (!$dryRun) Storage::disk($d->disk)->delete($d->path);
                            $filesDeleted++;
                            $this->line("  <fg=gray>file</> {$d->disk}://{$d->path}");
                        }
                        if (!$dryRun) $d->delete();
                        $rowsDeleted++;
                    } catch (\Throwable $e) {
                        $errors++;
                        \Log::warning('CleanupExpiredDownloads: error en Download id', [
                            'download_id' => $d->id,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }
            });

        // 2. Downloads ya descargados hace > grace horas — el archivo físico ya
        //    cumplió su propósito. Borramos archivo pero MANTENEMOS el row
        //    como historial (status='expired', path vacío). Útil para audit.
        Download::whereNotNull('downloaded_at')
            ->where('downloaded_at', '<', $downloadedCutoff)
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->chunkById(500, function ($downloads) use ($dryRun, &$filesDeleted, &$errors) {
                foreach ($downloads as $d) {
                    try {
                        if (Storage::disk($d->disk)->exists($d->path)) {
                            if (!$dryRun) Storage::disk($d->disk)->delete($d->path);
                            $filesDeleted++;
                            $this->line("  <fg=gray>file</> {$d->disk}://{$d->path} (descargado, >$grace h)");
                        }
                        if (!$dryRun) {
                            $d->update(['path' => '', 'status' => 'expired']);
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        \Log::warning('CleanupExpiredDownloads: error post-download', [
                            'download_id' => $d->id,
                            'error'       => $e->getMessage(),
                        ]);
                    }
                }
            });

        $verb = $dryRun ? 'Se borrarían' : 'Borrados';
        $this->newLine();
        $this->info("{$verb} {$filesDeleted} archivos, {$rowsDeleted} rows (expirados); {$staleFailed} atascados → failed.");
        if ($errors > 0) {
            $this->warn("{$errors} errores — revisar laravel.log");
        }

        return self::SUCCESS;
    }
}
