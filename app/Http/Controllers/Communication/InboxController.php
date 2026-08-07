<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\Message\StoreReplyRequest;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\MessageReply;
use App\Services\Communication\MessageService;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * InboxController — bandeja de mensajes para todos los users autenticados.
 *
 * El user solo ve mensajes donde figura como recipient (resuelto en la tabla
 * message_recipients). Cuando abre el detalle, el server marca read_at = now.
 */
class InboxController extends Controller
{
    public function __construct(protected MessageService $service)
    {
    }

    /**
     * Polling liviano del bell (downloads + notificaciones app + mensajes).
     * Devuelve el MISMO payload que el SSR (InboxService). El front lo consulta
     * con axios y actualiza estado local — sin router.reload, sin re-render de
     * la página (que era lo que reposicionaba el panel del bell en mobile).
     */
    public function poll(Request $request, \App\Services\Communication\InboxService $inbox)
    {
        return response()->json($inbox->payload($request->user()->id));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = (int) $request->get('per_page', 15);
        $perPage = in_array($perPage, [10, 15, 25, 50, 100]) ? $perPage : 15;

        $query = $this->service->inboxFor($user);

        if ($request->boolean('only_unread')) {
            $query->whereNull('message_recipients.read_at');
        }
        if ($request->boolean('only_repliable')) {
            $query->where('messages.allow_replies', true);
        }

        $messages = $query
            ->with(['creator:id,name,email,photo,updated_at'])
            ->paginate($perPage)
            ->withQueryString();

        // Map para mandar al frontend con snippet sin HTML.
        $messages->getCollection()->transform(function ($m) {
            $arr = $m->toArray();
            $arr['read_at'] = $m->read_at instanceof \Carbon\CarbonInterface
                ? $m->read_at->toIso8601String()
                : ($m->read_at ? (string) $m->read_at : null);
            $arr['snippet'] = $this->extractSnippet($m->body, 140);
            return $arr;
        });

        return inertia('Inbox/Index', [
            'messages' => $messages->toArray(),
            'filters'  => [
                'only_unread'    => $request->boolean('only_unread'),
                'only_repliable' => $request->boolean('only_repliable'),
                'per_page'       => $perPage,
            ],
        ]);
    }

    public function show(string $slug, Request $request)
    {
        $user = $request->user();

        $message = Message::where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->firstOrFail();

        // Valida que el user es recipient. Si no lo es, 403.
        $recipient = MessageRecipient::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();
        if (!$recipient) {
            abort(403, __('messages.not_a_recipient'));
        }

        // Marca como leido (no-op si ya estaba leido).
        $this->service->markAsRead($user, $message);

        $replies = $message->replies()
            ->with(['user:id,name,email,photo,updated_at'])
            ->get()
            ->map(fn ($r) => [
                'id'         => $r->id,
                'body'       => $r->body,
                'user'       => $r->user ? [
                    'id'        => $r->user->id,
                    'name'      => $r->user->name,
                    'email'     => $r->user->email,
                    'photo_url' => $r->user->photo_url,
                ] : null,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();

        return inertia('Inbox/Show', [
            'message' => array_merge($message->toArray(), [
                'creator' => $message->creator ? [
                    'id'        => $message->creator->id,
                    'name'      => $message->creator->name,
                    'email'     => $message->creator->email,
                    'photo_url' => $message->creator->photo_url,
                ] : null,
                'read_at' => $recipient->read_at?->toIso8601String() ?? now()->toIso8601String(),
            ]),
            'replies'   => $replies,
            'can_reply' => $message->allow_replies,
        ]);
    }

    public function reply(StoreReplyRequest $request, string $slug)
    {
        $user = $request->user();

        $message = Message::where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('is_active', true)
            ->firstOrFail();

        if (!$message->allow_replies) {
            abort(403, __('messages.replies_not_allowed'));
        }

        $isRecipient = MessageRecipient::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->exists();
        $isCreator = $message->created_by === $user->id;
        if (!$isRecipient && !$isCreator) {
            abort(403, __('messages.not_a_recipient'));
        }

        $reply = MessageReply::create([
            'message_id' => $message->id,
            'user_id'    => $user->id,
            'body'       => $request->validated()['body'],
        ]);

        // Notificar al creador del mensaje (in-app via tabla notifications).
        // Si el que respondio es el propio creador, no se notifica a si mismo.
        if (!$isCreator && $message->creator) {
            $this->pushReplyNotification($message, $reply, $user);
        }

        return redirect()
            ->route('communication.inbox.show', $message->slug)
            ->with('success', __('messages.reply_sent'));
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $count = $this->service->markAllAsRead($user);

        return back()->with('success', __('messages.mark_all_read_success', ['count' => $count]));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function extractSnippet(string $html, int $length = 140): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . '...';
    }

    /**
     * Inserta una notificacion in-app para el creador del mensaje cuando
     * alguien responde. Usa la tabla `notifications` estandar de Laravel para
     * que el bell la consuma automaticamente (HandleInertiaRequests ya la lee).
     */
    protected function pushReplyNotification(Message $message, MessageReply $reply, $fromUser): void
    {
        DB::table('notifications')->insert([
            'id'              => (string) Str::uuid(),
            'type'            => 'App\\Notifications\\MessageReplyNotification',
            'notifiable_type' => \App\Models\User::class,
            'notifiable_id'   => $message->created_by,
            'data' => json_encode([
                'category'   => 'message_reply',
                'title'      => __('messages.notify_new_reply_title'),
                'body'       => __('messages.notify_new_reply_body', [
                    'user'    => $fromUser->name,
                    'subject' => $message->subject,
                ]),
                'message_slug' => $message->slug,
                'reply_id'     => $reply->id,
            ]),
            'read_at'    => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
