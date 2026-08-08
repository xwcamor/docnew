<?php

namespace App\Jobs\BusinessManagement\WorkTypes;

use App\Models\WorkType;
use App\Services\BusinessManagement\WorkTypeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bulk operations en background cuando count > asyncThreshold().
 * Actions: 'delete' | 'set_active' | 'restore'.
 *
 * Clon del patron de BulkApprovalRulesActionJob — el threshold y el wiring de
 * dispatch viven en WorkTypeService.
 *
 * ShouldBeUnique: si el worker muere mid-execution y el supervisor lo retry, el
 * lock por hash(userId+action+ids) impide reprocesar mientras el job original
 * sigue activo. Evita audit log doble + notificaciones duplicadas en el bell.
 */
class BulkWorkTypesActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));

        return "bulk:work_types:{$this->userId}:{$this->action}:{$idsHash}";
    }

    /** Umbral configurable: Setting global -> config/work_types.php -> 200. */
    public static function asyncThreshold(): int
    {
        return \App\Models\Setting::getInt(
            'bulk.async_threshold',
            (int) config('work_types.bulk_async_threshold', 200),
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

    public function handle(WorkTypeService $service): void
    {
        // Setear auth() en el worker -> audit log con el user_id correcto. Si el
        // user desaparecio entre dispatch y ejecucion se falla en vez de dejar
        // audit_logs sin "quien".
        $user = \App\Models\User::find($this->userId);
        if (! $user) {
            \Log::warning('BulkWorkTypesActionJob: user not found, aborting', [
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
            $tipos = match ($this->action) {
                'restore' => WorkType::onlyTrashed()->whereIn('id', $chunk)->get(),
                default   => WorkType::whereIn('id', $chunk)->get(),
            };

            foreach ($tipos as $tipo) {
                try {
                    match ($this->action) {
                        'delete'     => $service->delete($tipo, $this->payload['reason'] ?? 'Bulk delete'),
                        'set_active' => $this->setActive($service, $tipo),
                        'restore'    => $service->restore($tipo),
                        default      => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
                    };
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning("BulkWorkTypesActionJob: error on workType {$tipo->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info('BulkWorkTypesActionJob completed', [
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
        \Log::error('BulkWorkTypesActionJob failed', [
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
            \Log::warning('BulkWorkTypesActionJob: notify failed', ['error' => $e->getMessage()]);
        }
    }

    protected function setActive(WorkTypeService $service, WorkType $tipo): void
    {
        $target = (bool) ($this->payload['is_active'] ?? true);
        if ((bool) $tipo->is_active === $target) {
            return;
        }
        $service->update($tipo, ['is_active' => $target]);
    }
}
