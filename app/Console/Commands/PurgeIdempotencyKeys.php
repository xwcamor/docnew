<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Borra las claves de idempotencia vencidas.
 *
 * La tabla es un registro técnico de la API, no un dato de negocio: guarda la
 * respuesta de cada envío del laboratorio para poder repetirla si reintentan.
 * Pasada la ventana (`lab_integration.idempotency_ttl_days`, 30 días por
 * defecto, muy por encima del último reintento de su cola, que es a las 6
 * horas) ya nadie va a reenviar esa clave y la fila solo ocupa lugar.
 *
 * NO va en config/purge.php: eso purga registros con soft-delete de módulos de
 * negocio, y acá no hay nada que restaurar.
 *
 * Schedule: diario (routes/console.php).
 *
 * Modos:
 *   php artisan api:purge-idempotency-keys
 *   php artisan api:purge-idempotency-keys --dry-run
 */
class PurgeIdempotencyKeys extends Command
{
    protected $signature = 'api:purge-idempotency-keys {--dry-run : Solo reporta, no borra}';

    protected $description = 'Borra las claves de idempotencia de la API que ya vencieron';

    public function handle(): int
    {
        $query = IdempotencyKey::whereNotNull('expires_at')->where('expires_at', '<', now());
        $count = $query->count();

        if ($count === 0) {
            $this->info('No hay claves de idempotencia vencidas.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Se borrarían {$count} claves vencidas.");

            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Borradas {$count} claves de idempotencia vencidas.");

        return self::SUCCESS;
    }
}
