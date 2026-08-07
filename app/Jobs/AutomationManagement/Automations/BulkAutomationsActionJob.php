<?php

namespace App\Jobs\AutomationManagement\Automations;

use App\Models\Automation;
use App\Services\AutomationManagement\AutomationService;
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
 * Particularidad de Automation: el delete del service necesita $userId
 * explicito (no toma auth()->id() internamente). Lo seteamos en el worker
 * via auth()->setUser() y se lo pasamos al service.
 *
 * set_active aquí usa el patron del job — el service AutomationService
 * tiene un bulkSetActive sync, pero al venir por job iteramos manualmente
 * para mantener simetria con Regions/Languages y reusar `update()` (que
 * recalcula next_run_at). Para set_active sin update general, llamamos
 * al toggle manual abajo en setActive().
 *
 * ShouldBeUnique: lock por hash(userId+action+ids) para que retries del worker
 * no dupliquen audit log ni notificaciones. TTL = 1800s = timeout del job.
 */
class BulkAutomationsActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));
        return "bulk:automations:{$this->userId}:{$this->action}:{$idsHash}";
    }

    public static function asyncThreshold(): int
    {
        return \App\Models\Setting::getInt(
            'bulk.async_threshold',
            (int) config('automations.bulk_async_threshold', 200),
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

    public function handle(AutomationService $service): void
    {
        $user = \App\Models\User::find($this->userId);
        if (!$user) {
            \Log::warning('BulkAutomationsActionJob: user not found, aborting', [
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
            $automations = match ($this->action) {
                'restore' => Automation::onlyTrashed()->whereIn('id', $chunk)->get(),
                default   => Automation::whereIn('id', $chunk)->get(),
            };

            foreach ($automations as $automation) {
                try {
                    match ($this->action) {
                        'delete'     => $service->delete(
                            $automation,
                            $this->payload['reason'] ?? 'Bulk delete',
                            $this->userId,
                        ),
                        'set_active' => $this->setActive($automation),
                        'restore'    => $service->restore($automation),
                        default      => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
                    };
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning("BulkAutomationsActionJob: error on automation {$automation->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info("BulkAutomationsActionJob completed", [
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
        \Log::error("BulkAutomationsActionJob failed", [
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
            \Log::warning('BulkAutomationsActionJob: notify failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Setea is_active al target y recalcula next_run_at. Mismo patron que
     * el bulkSetActive sync del service, replicado aquí para que el job no
     * dependa de un loop adicional en el service.
     */
    protected function setActive(Automation $automation): void
    {
        $target = (bool) ($this->payload['is_active'] ?? true);
        if ((bool) $automation->is_active === $target) return;
        $automation->is_active   = $target;
        $automation->next_run_at = $target ? $automation->computeNextRunAt(now()) : null;
        $automation->save();
    }
}
