<?php

namespace App\Jobs\SystemManagement\Languages;

use App\Models\Language;
use App\Services\SystemManagement\LanguageService;
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
class BulkLanguagesActionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 1800;

    public function uniqueId(): string
    {
        $idsHash = md5(implode(',', array_map('intval', $this->ids)));
        return "bulk:languages:{$this->userId}:{$this->action}:{$idsHash}";
    }

    public static function asyncThreshold(): int
    {
        return \App\Models\Setting::getInt(
            'bulk.async_threshold',
            (int) config('languages.bulk_async_threshold', 200),
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

    public function handle(LanguageService $service): void
    {
        // Setear auth() en el worker → audit log con user_id correcto.
        // Si el user fue borrado entre dispatch y ejecución, fallamos
        // elegante: sin user, audit_logs quedarían con user_id NULL y
        // perderíamos el "quién" en forensics.
        $user = \App\Models\User::find($this->userId);
        if (!$user) {
            \Log::warning('BulkLanguagesActionJob: user not found, aborting', [
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
            $languages = match ($this->action) {
                'restore' => Language::onlyTrashed()->whereIn('id', $chunk)->get(),
                default   => Language::whereIn('id', $chunk)->get(),
            };

            foreach ($languages as $language) {
                try {
                    match ($this->action) {
                        'delete'     => $service->delete($language, $this->payload['reason'] ?? 'Bulk delete'),
                        'set_active' => $this->setActive($service, $language),
                        'restore'    => $service->restore($language),
                        default      => throw new \InvalidArgumentException("Unknown action: {$this->action}"),
                    };
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Log::warning("BulkLanguagesActionJob: error on language {$language->id}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        \Log::info("BulkLanguagesActionJob completed", [
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
        \Log::error("BulkLanguagesActionJob failed", [
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
            \Log::warning('BulkLanguagesActionJob: notify failed', ['error' => $e->getMessage()]);
        }
    }

    protected function setActive(LanguageService $service, Language $language): void
    {
        $target = (bool) ($this->payload['is_active'] ?? true);
        if ((bool) $language->is_active === $target) return;
        $service->update($language, ['is_active' => $target]);
    }
}
