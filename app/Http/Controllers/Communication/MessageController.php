<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\Message\DeleteMessageRequest;
use App\Http\Requests\Communication\Message\StoreMessageRequest;
use App\Http\Requests\Communication\Message\UpdateMessageRequest;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Communication\MessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Permisos: todas las rutas viven dentro del middleware `role:super` en
 * routes/communication.php. No hace falta validar rol por metodo.
 */
class MessageController extends Controller
{
    public function __construct(protected MessageService $service)
    {
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100, 200]) ? $perPage : 10;

        $query = Message::query()
            ->withCount(['recipients as recipients_count', 'replies as replies_count'])
            ->withCount(['recipients as read_count' => function ($q) {
                $q->whereNotNull('read_at');
            }])
            ->with(['creator:id,name,email,photo,updated_at']);

        if ($request->filled('subject')) {
            $query->where('subject', 'like', '%' . $request->subject . '%');
        }
        if ($request->filled('audience_type')) {
            $query->where('audience_type', $request->audience_type);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        $messages = $query->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('Messages/Index', [
            'messages' => $messages->toArray(),
            'filters' => [
                'subject'       => $request->get('subject', ''),
                'audience_type' => $request->get('audience_type', ''),
                'is_active'     => $request->filled('is_active')
                    ? filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)
                    : null,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function create()
    {
        return inertia('Messages/Form', [
            'message'  => null,
            'tenants'  => $this->loadTenants(),
            'users'    => $this->loadUsers(),
        ]);
    }

    public function store(StoreMessageRequest $request)
    {
        $data = $request->validated();
        $publishNow = (bool) ($data['publish_now'] ?? false);
        unset($data['publish_now']);

        $data['created_by']    = $request->user()->id;
        $data['allow_replies'] = (bool) ($data['allow_replies'] ?? false);
        $data['is_active']     = (bool) ($data['is_active'] ?? true);

        // Si la audiencia es global, audience_id se ignora.
        if ($data['audience_type'] === Message::AUDIENCE_GLOBAL) {
            $data['audience_id'] = null;
        }

        $message = DB::transaction(function () use ($data, $publishNow) {
            $message = Message::create($data);
            if ($publishNow) {
                $this->service->publish($message);
            }
            return $message;
        });

        return redirect()
            ->route('communication.messages.show', $message->slug)
            ->with('success', __('messages.created_success'));
    }

    public function show(string $slug, Request $request)
    {
        // creator necesita photo + updated_at en el select para que el
        // accessor photo_url funcione al serializar.
        $message = Message::with(['creator:id,name,email,photo,updated_at'])
            ->where('slug', $slug)
            ->firstOrFail();

        $stats = [
            'recipients_count' => MessageRecipient::where('message_id', $message->id)->count(),
            'read_count'       => MessageRecipient::where('message_id', $message->id)->whereNotNull('read_at')->count(),
        ];
        $stats['read_pct'] = $stats['recipients_count'] > 0
            ? round(($stats['read_count'] * 100) / $stats['recipients_count'], 1)
            : 0;

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

        $audienceName = $this->resolveAudienceName($message);

        return inertia('Messages/Show', [
            'message'       => array_merge($message->toArray(), [
                'audience_name' => $audienceName,
            ]),
            'stats'         => $stats,
            'replies'       => $replies,
        ]);
    }

    public function edit(string $slug)
    {
        $message = Message::where('slug', $slug)->firstOrFail();

        return inertia('Messages/Form', [
            'message'  => $message->toArray(),
            'tenants'  => $this->loadTenants(),
            'users'    => $this->loadUsers(),
        ]);
    }

    public function update(UpdateMessageRequest $request, string $slug)
    {
        $message = Message::where('slug', $slug)->firstOrFail();
        $data = $request->validated();
        $publishNow = (bool) ($data['publish_now'] ?? false);
        unset($data['publish_now']);

        $data['allow_replies'] = (bool) ($data['allow_replies'] ?? false);
        $data['is_active']     = (bool) ($data['is_active'] ?? true);

        // Si era global, audience_id va a null (consistency).
        if ($data['audience_type'] === Message::AUDIENCE_GLOBAL) {
            $data['audience_id'] = null;
        }

        DB::transaction(function () use ($message, $data, $publishNow) {
            $message->update($data);
            // NO re-creamos recipients si ya estaba publicado: cambiar la
            // audiencia despues de publicar es UB. El super puede crear un
            // mensaje nuevo si quiere otra audiencia.
            if ($publishNow && !$message->isPublished()) {
                $this->service->publish($message);
            }
        });

        return redirect()
            ->route('communication.messages.show', $message->slug)
            ->with('success', __('messages.updated_success'));
    }

    public function delete(string $slug)
    {
        $message = Message::where('slug', $slug)->firstOrFail();
        return inertia('Messages/Delete', [
            'message' => $message->toArray(),
        ]);
    }

    public function deleteSave(DeleteMessageRequest $request, string $slug)
    {
        $message = Message::where('slug', $slug)->firstOrFail();

        // Soft-delete neto: la tabla messages no tiene deleted_description.
        // El motivo queda en el audit log (escrito por el trait Auditable).
        $message->delete();

        return redirect()
            ->route('communication.messages.index')
            ->with('success', __('messages.deleted_success'));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    protected function loadTenants(): array
    {
        return Tenant::query()
            ->withoutGlobalScopes() // super ve todos
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'slug'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug])
            ->all();
    }

    protected function loadUsers(): array
    {
        // super puede dirigir un mensaje a cualquier user humano.
        return User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'api'))
            ->orderBy('name')
            ->limit(1000)
            ->get(['id', 'name', 'email', 'tenant_id'])
            ->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => $u->name,
                'email'     => $u->email,
                'tenant_id' => $u->tenant_id,
            ])
            ->all();
    }

    protected function resolveAudienceName(Message $message): ?string
    {
        if ($message->audience_type === Message::AUDIENCE_GLOBAL) {
            return __('messages.audience_global');
        }
        if ($message->audience_type === Message::AUDIENCE_TENANT && $message->audience_id) {
            $t = Tenant::withoutGlobalScopes()->find($message->audience_id);
            return $t?->name;
        }
        if ($message->audience_type === Message::AUDIENCE_USER && $message->audience_id) {
            $u = User::withoutGlobalScopes()->find($message->audience_id);
            return $u ? "{$u->name} <{$u->email}>" : null;
        }
        return null;
    }
}
