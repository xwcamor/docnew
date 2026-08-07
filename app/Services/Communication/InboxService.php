<?php

namespace App\Services\Communication;

use App\Models\Download;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * InboxService — arma el payload del inbox del bell (downloads + notificaciones
 * app + mensajes). ÚNICO origen de verdad: lo usa tanto el SSR inicial
 * (HandleInertiaRequests, como prop `inbox`) como el endpoint de polling
 * (Communication\InboxController::poll, como JSON). Así el front puede refrescar
 * con un fetch liviano sin re-renderizar la página (sin router.reload).
 */
class InboxService
{
    public function payload(int $userId): array
    {
        $toIso = function ($value): ?string {
            if ($value === null) {
                return null;
            }
            if ($value instanceof \Carbon\CarbonInterface) {
                return $value->toIso8601String();
            }
            try {
                return \Carbon\Carbon::parse($value)->toIso8601String();
            } catch (\Throwable $e) {
                return null;
            }
        };

        // ── Downloads (exports listos) ───────────────────────────────────
        $downloads = Download::where('user_id', $userId)
            ->where('expires_at', '>=', now())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'slug', 'type', 'filename', 'status', 'created_at', 'downloaded_at', 'error_message'])
            ->map(fn ($d) => [
                'id'            => "dl-{$d->id}",
                'slug'          => $d->slug,
                'kind'          => 'download',
                'type'          => $d->type,
                'filename'      => $d->filename,
                'status'        => $d->status,
                'created_at'    => $toIso($d->created_at),
                'downloaded_at' => $toIso($d->downloaded_at),
                'error_message' => $d->error_message,
            ])
            ->all();

        // ── App notifications (tabla `notifications` estándar de Laravel) ──
        $appNotifs = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'type', 'data', 'read_at', 'created_at'])
            ->map(function ($n) use ($toIso) {
                $data = json_decode($n->data, true) ?? [];
                return [
                    'id'          => "app-{$n->id}",
                    'raw_id'      => $n->id,
                    'kind'        => 'app',
                    'type'        => $data['category'] ?? class_basename($n->type),
                    'title'       => $data['title'] ?? '',
                    'body'        => $data['body']  ?? '',
                    'tenant_name' => $data['tenant_name'] ?? null,
                    'channel'     => $data['channel'] ?? null,
                    'status'      => $n->read_at ? 'read' : 'unread',
                    'created_at'  => $toIso($n->created_at),
                    'read_at'     => $toIso($n->read_at),
                ];
            })
            ->all();

        // ── Merge + sort por fecha ────────────────────────────────────────
        $recent = collect($downloads)->concat($appNotifs)
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->all();

        $unread = collect($recent)->filter(function ($n) {
            if ($n['kind'] === 'download') {
                return $n['status'] === 'ready' && empty($n['downloaded_at']);
            }
            if ($n['kind'] === 'app') {
                return $n['status'] === 'unread';
            }
            return false;
        })->count();

        $processing = collect($recent)
            ->filter(fn ($n) => $n['kind'] === 'download' && $n['status'] === 'processing')
            ->count();

        // ── Mensajes (módulo Communication) ───────────────────────────────
        $unreadMessages = 0;
        $messagesPreview = [];
        try {
            $service = app(MessageService::class);
            $unreadMessages = $service->unreadCountForUser($userId);

            $messagesPreview = $service->inboxFor($userId)
                ->orderByDesc('messages.published_at')
                ->limit(5)
                ->get(['messages.id', 'messages.slug', 'messages.subject',
                       'messages.published_at', 'message_recipients.read_at'])
                ->map(fn ($m) => [
                    'id'         => "msg-{$m->id}",
                    'slug'       => $m->slug,
                    'kind'       => 'message',
                    'subject'    => $m->subject,
                    'status'     => $m->read_at ? 'read' : 'unread',
                    'created_at' => $toIso($m->published_at),
                    'read_at'    => $toIso($m->read_at),
                ])
                ->all();
        } catch (\Throwable $e) {
            $unreadMessages = 0;
            $messagesPreview = [];
        }

        return [
            'recent'          => $recent,
            'unread'          => $unread,
            'processing'      => $processing,
            'unread_messages' => $unreadMessages,
            'messages'        => $messagesPreview,
        ];
    }
}
