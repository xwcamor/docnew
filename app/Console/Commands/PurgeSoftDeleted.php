<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PurgeSoftDeleted — borra físicamente registros soft-deleted antiguos.
 *
 * Para cada módulo configurado en `config/purge.php`:
 *   1. Busca registros con deleted_at < (now - days)
 *   2. Si tiene anonymize, reemplaza campos PII con random antes de borrar
 *   3. Hard-deletea por chunks (no carga todo a memoria)
 *   4. Escribe un audit log resumen ('purged', count, módulo)
 *
 * Diseñado para correr nightly via Schedule. Idempotente: si no hay nada
 * elegible, sale sin hacer nada.
 *
 * Modos:
 *   php artisan app:purge-soft-deleted              # corre todos los módulos
 *   php artisan app:purge-soft-deleted regions      # solo uno
 *   php artisan app:purge-soft-deleted --dry-run    # solo reporta
 *   php artisan app:purge-soft-deleted --module=regions --dry-run
 */
class PurgeSoftDeleted extends Command
{
    protected $signature = 'app:purge-soft-deleted
        {module? : Module slug (regions, users, etc.). Si se omite, corre todos.}
        {--dry-run : Solo reporta qué se borraría, no toca nada.}
        {--days= : Override del grace period del config para este run.}';

    protected $description = 'Purga registros soft-deleted más viejos que el grace period configurado';

    public function handle(): int
    {
        $moduleArg = $this->argument('module');
        $dryRun    = (bool) $this->option('dry-run');
        $daysOverride = $this->option('days');

        $modules = config('purge.modules', []);
        if (empty($modules)) {
            $this->warn('config/purge.php está vacío. Nada que purgar.');
            return self::SUCCESS;
        }

        if ($moduleArg) {
            if (!isset($modules[$moduleArg])) {
                $this->error("Módulo '{$moduleArg}' no está configurado en config/purge.php");
                return self::FAILURE;
            }
            $modules = [$moduleArg => $modules[$moduleArg]];
        }

        $totalDeleted = 0;
        foreach ($modules as $key => $cfg) {
            $deleted = $this->purgeModule($key, $cfg, $dryRun, $daysOverride);
            $totalDeleted += $deleted;
        }

        $verb = $dryRun ? 'Se borrarían' : 'Borrados';
        $this->newLine();
        $this->info("{$verb} {$totalDeleted} registros en total.");

        return self::SUCCESS;
    }

    protected function purgeModule(string $key, array $cfg, bool $dryRun, ?string $daysOverride): int
    {
        // Resolucion del grace period con la cascada:
        //   --days override > setting global por modulo > config/purge.php > 0
        // Para 'audit_logs' el setting `audit.retention_days` (default 365)
        // permite ajustar la retencion desde la UI sin redeploy.
        $settingDays = $key === 'audit_logs'
            ? \App\Models\Setting::getInt('audit.retention_days', 0)
            : 0;
        $days = (int) ($daysOverride ?? ($settingDays > 0 ? $settingDays : ($cfg['days'] ?? 0)));
        if ($days <= 0) {
            $this->line("  <fg=yellow>{$key}: skipping (days <= 0, deshabilitado)</>");
            return 0;
        }

        $modelClass = $cfg['model'] ?? null;
        if (!$modelClass || !class_exists($modelClass)) {
            $this->error("  {$key}: modelo no válido en config");
            return 0;
        }

        if (!method_exists($modelClass, 'bootSoftDeletes')) {
            $this->error("  {$key}: el modelo {$modelClass} no usa SoftDeletes");
            return 0;
        }

        $cutoff = now()->subDays($days);
        $chunk  = (int) ($cfg['chunk'] ?? 500);
        $anonymize = $cfg['anonymize'] ?? null;

        // Contar elegibles
        $eligible = $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->count();

        if ($eligible === 0) {
            $this->line("  <fg=gray>{$key}: nada elegible (cutoff: {$cutoff->toDateString()})</>");
            return 0;
        }

        $action = $anonymize ? 'anonymize+delete' : 'force-delete';
        $this->line("  <fg=cyan>{$key}: {$eligible} registros elegibles ({$action}, cutoff: {$cutoff->toDateString()})</>");

        if ($dryRun) {
            return $eligible;
        }

        // Procesar en chunks para no cargar 100k filas a memoria de una.
        $deleted = 0;
        $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->chunkById($chunk, function ($records) use (&$deleted, $anonymize, $modelClass) {
                foreach ($records as $record) {
                    // Archivos personales físicos (foto/firma): borrarlos ANTES
                    // de anonimizar — después los paths ya no son los reales.
                    if (method_exists($record, 'cleanupPersonalFiles')) {
                        $record->cleanupPersonalFiles();
                    }
                    if ($anonymize) {
                        // Reemplazar columnas PII con random antes del delete.
                        // Preserva la fila físicamente (sigue eligible para
                        // delete, pero ya no contiene PII en backups).
                        $patch = [];
                        foreach ($anonymize as $col) {
                            $patch[$col] = '__anon_' . Str::random(10);
                        }
                        // Update silencioso (sin tocar updated_at ni audit) — ya
                        // estamos por borrarla, no hay sentido en eventos.
                        DB::table((new $modelClass)->getTable())
                            ->where('id', $record->id)
                            ->update($patch);
                    }
                    $record->forceDelete();
                    $deleted++;
                }
            });

        // Audit summary — un solo registro por purga, no uno por fila.
        \App\Models\AuditLog::create([
            'user_id'        => null,  // sistema, no usuario
            'event'          => 'purged',
            'auditable_type' => $modelClass,
            'auditable_id'   => null,
            'old_values'     => null,
            'new_values'     => ['count' => $deleted, 'cutoff_days' => $days],
            'note'           => "Purge automático: {$deleted} registros con deleted_at < {$cutoff->toDateString()}",
            'module'         => $key,
            'created_at'     => now(),
        ]);

        return $deleted;
    }
}
