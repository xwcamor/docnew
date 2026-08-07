<?php

namespace App\Jobs\AuthManagement\Users;

use App\Models\User;
use App\Services\AuthManagement\UserService;
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
 * Clon del patron de BulkRegionsActionJob. Para Users el dispatch viene
 * de UserController (post-validacion + tenant + self-exclude) — el job
 * solo itera los IDs ya filtrados.
 *
 * ShouldBeUnique: lock por hash(userId+action+ids) para que retries del worker
 * no dupliquen audit log ni notificaciones. TTL = 1800s = timeout del job.
 */
class BulkUsersActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));
        return "bulk:users:{$this->userId}:{$this->action}:{$idsHash}";
    }

    public static function asyncThreshold(): int
    {
        return \App\Models\Setting::getInt(
            'bulk.async_threshold',
            (int) config('users.bulk_async_threshold', 200),
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

    public function handle(UserService $service): void
    {
        $authUser = User::find($this->userId);
        if (!$authUser) {
            \Log::warning('BulkUsersActionJob: user not found, aborting', [
                'user_id' => $this->userId,
                'action'  => $this->action,
            ]);
            $this->fail(new \RuntimeException("User {$this->userId} not found"));
            return;
        }
        auth()->setUser($authUser);

        $processed = 0;
        $errors    = 0;

        foreach (array_chunk($this->ids, 200) as $chunk) {
            $users = match ($this->action) {
                'restore' => User::onlyTrashed()->whereIn('id', $chunk)->get(),
                default   => User::whereIn('id', $chunk)->get(),
            };

            foreach ($users as $user) {
                try {
                    match ($this->action) {
                        'delete'     => $service->delete($user, $this->payload['reason'] ?? 'Bulk delete'),
                        'set_active' => $this->setActive($user),
                        'restore'    => $service->restore($user),
                        default      => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
                    };
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning("BulkUsersActionJob: error on user {$user->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info("BulkUsersActionJob completed", [
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
        \Log::error("BulkUsersActionJob failed", [
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
            \Log::warning('BulkUsersActionJob: notify failed', ['error' => $e->getMessage()]);
        }
    }

    protected function setActive(User $user): void
    {
        $target = (bool) ($this->payload['is_active'] ?? true);
        if ((bool) $user->is_active === $target) return;
        $user->is_active = $target;
        $user->save();
    }
}
