<?php

namespace App\Jobs\SystemManagement\SystemModules;

use App\Models\SystemModule;
use App\Services\SystemManagement\SystemModuleService;
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
 * ShouldBeUnique: lock por hash(userId+action+ids) para que retries del worker
 * no dupliquen audit log ni notificaciones. TTL = 1800s = timeout del job.
 */
class BulkSystemModulesActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));
        return "bulk:system_modules:{$this->userId}:{$this->action}:{$idsHash}";
    }

    public static function asyncThreshold(): int
    {
        return \App\Models\Setting::getInt(
            'bulk.async_threshold',
            (int) config('system_modules.bulk_async_threshold', 200),
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

    public function handle(SystemModuleService $service): void
    {
        // Setear auth() en el worker → audit log con user_id correcto.
        // Si el user fue borrado entre dispatch y ejecución, fallamos
        // elegante: sin user, audit_logs quedarían con user_id NULL y
        // perderíamos el "quién" en forensics.
        $user = \App\Models\User::find($this->userId);
        if (!$user) {
            \Log::warning('BulkSystemModulesActionJob: user not found, aborting', [
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
            $system_modules = match ($this->action) {
                'restore' => SystemModule::onlyTrashed()->whereIn('id', $chunk)->get(),
                default   => SystemModule::whereIn('id', $chunk)->get(),
            };

            foreach ($system_modules as $system_module) {
                try {
                    match ($this->action) {
                        'delete'     => $service->delete($system_module, $this->payload['reason'] ?? 'Bulk delete'),
                        'set_active' => $this->setActive($service, $system_module),
                        'restore'    => $service->restore($system_module),
                        default      => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
                    };
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning("BulkSystemModulesActionJob: error on system_module {$system_module->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info("BulkSystemModulesActionJob completed", [
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
        \Log::error("BulkSystemModulesActionJob failed", [
            'user_id' => $this->userId,
            'action'  => $this->action,
            'total'   => count($this->ids),
            'error'   => $exception->getMessage(),
        ]);

        $this->notifyUser('failed', $exception->getMessage());
    }

    /** Crea entrada en `downloads` con type=task → aparece en el bell. */
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
            \Log::warning('BulkSystemModulesActionJob: notify failed', ['error' => $e->getMessage()]);
        }
    }

    protected function setActive(SystemModuleService $service, SystemModule $system_module): void
    {
        $target = (bool) ($this->payload['is_active'] ?? true);
        if ((bool) $system_module->is_active === $target) return;
        $service->update($system_module, ['is_active' => $target]);
    }
}
