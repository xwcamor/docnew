<?php

namespace App\Jobs\BusinessManagement\Transformers;

use App\Models\Transformer;
use App\Services\BusinessManagement\TransformerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bulk operations en background cuando count > asyncThreshold().
 * Actions: 'delete' | 'restore'.
 *
 * Clon del patron de BulkRegionsActionJob — el threshold y el wiring de
 * dispatch viven en TransformerService.
 *
 * ShouldBeUnique: si el worker muere mid-execution y el supervisor lo retry,
 * el lock por hash(userId+action+ids) impide que se reprocese mientras el job
 * original sigue activo. Evita audit log doble + notificaciones duplicadas en
 * el bell. TTL = 30min (más que el timeout del job, 1800s).
 */
class BulkTransformersActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));
        return "bulk:transformers:{$this->userId}:{$this->action}:{$idsHash}";
    }

    /**
     * Umbral configurable. Prioridad: Setting global -> config/transformers.php -> 200.
     * Permite override en runtime sin redeploy (super desde la UI).
     */
    public static function asyncThreshold(): int
    {
        return \App\Models\Setting::getInt(
            'bulk.async_threshold',
            (int) config('transformers.bulk_async_threshold', 200),
        );
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    protected int $userId;
    protected string $action;
    protected array $ids;
    protected array $payload;

    public function __construct(int $userId, string $action, array $ids, array $payload = [])
    {
        $this->userId  = $userId;
        $this->action  = $action;
        $this->ids     = $ids;
        $this->payload = $payload;
    }

    public function handle(TransformerService $service): void
    {
        // Setear auth() en el worker -> audit log con user_id correcto.
        // Si el user fue borrado entre dispatch y ejecucion, fallamos
        // elegante: sin user, audit_logs quedarian con user_id NULL y
        // perderiamos el "quien" en forensics.
        $user = \App\Models\User::find($this->userId);
        if (!$user) {
            \Log::warning('BulkTransformersActionJob: user not found, aborting', [
                'user_id' => $this->userId,
                'action'  => $this->action,
            ]);
            $this->fail(new \RuntimeException("User {$this->userId} not found"));
            return;
        }
        auth()->setUser($user);

        $processed = 0;
        $errors    = 0;

        foreach (array_chunk($this->ids, 200) as $chunk) {
            $transformers = match ($this->action) {
                'restore' => Transformer::onlyTrashed()->whereIn('id', $chunk)->get(),
                default   => Transformer::whereIn('id', $chunk)->get(),
            };

            foreach ($transformers as $transformer) {
                try {
                    match ($this->action) {
                        'delete'  => $service->delete($transformer, $this->payload['reason'] ?? 'Bulk delete'),
                        'restore' => $service->restore($transformer),
                        default   => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
                    };
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning("BulkTransformersActionJob: error on transformer {$transformer->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info("BulkTransformersActionJob completed", [
            'user_id'   => $this->userId,
            'action'    => $this->action,
            'processed' => $processed,
            'errors'    => $errors,
            'total'     => count($this->ids),
        ]);

        $this->notifyUser('completed');
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error("BulkTransformersActionJob failed", [
            'user_id' => $this->userId,
            'action'  => $this->action,
            'total'   => count($this->ids),
            'error'   => $exception->getMessage(),
        ]);

        $this->notifyUser('failed', $exception->getMessage());
    }

    /** Crea entrada en `downloads` con type=task -> aparece en el bell. */
    protected function notifyUser(string $status, ?string $error = null): void
    {
        try {
            \App\Models\Download::create([
                'slug'          => \Illuminate\Support\Str::random(22),
                'user_id'       => $this->userId,
                'type'          => 'task',
                'filename'      => "bulk_{$this->action}",
                'path'          => '',
                'disk'          => 'local',
                'status'        => $status === 'completed' ? 'ready' : 'failed',
                'error_message' => $error,
                'expires_at'    => \App\Models\Download::computeExpiresAt(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('BulkTransformersActionJob: notify failed', ['error' => $e->getMessage()]);
        }
    }

}
