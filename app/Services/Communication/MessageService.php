<?php

namespace App\Services\Communication;

use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * MessageService — business logic del modulo Messages + Inbox.
 *
 * El controller del super crea el Message y luego llama publish() para
 * materializar los recipients segun audience_type. La separacion permite que
 * el super edite un draft (sin recipients) y publique despues.
 */
class MessageService
{
    /**
     * Publica el mensaje:
     *   1. Calcula recipients segun audience_type (excluye api/system users).
     *   2. Inserta filas en message_recipients (skip duplicates).
     *   3. Setea published_at = now() si no estaba.
     *
     * Idempotente: si se llama dos veces, no duplica recipients (unique key).
     *
     * @return int cantidad de recipients creados en esta ejecucion.
     */
    public function publish(Message $message): int
    {
        $userIds = $this->resolveAudienceUserIds($message);

        if (empty($userIds)) {
            // No hay audiencia: igual marcamos como publicado para que la UI
            // refleje el cambio. Recipients = 0 es valido (puede no haber
            // users del tenant o el user destino fue borrado).
            if (!$message->isPublished()) {
                $message->forceFill(['published_at' => now()])->save();
            }
            return 0;
        }

        // Excluir los user_ids que ya tienen fila para evitar violar el unique.
        $existing = MessageRecipient::where('message_id', $message->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();
        $newIds = array_diff($userIds, $existing);

        $now = now();
        $rows = [];
        foreach ($newIds as $uid) {
            $rows[] = [
                'message_id' => $message->id,
                'user_id'    => $uid,
                'read_at'    => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            // Chunk para mensajes globales grandes (miles de users).
            foreach (array_chunk($rows, 500) as $batch) {
                MessageRecipient::insert($batch);
            }
        }

        if (!$message->isPublished()) {
            $message->forceFill(['published_at' => $now])->save();
        }

        return count($newIds);
    }

    /**
     * Resuelve la lista de user_ids destinatarios para el mensaje.
     * Excluye en todos los casos los users con rol `api` (system users).
     *
     * @return int[]
     */
    public function resolveAudienceUserIds(Message $message): array
    {
        $query = User::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true);

        // Filtramos system users (rol api) — no son personas reales.
        $query->whereDoesntHave('roles', function ($q) {
            $q->where('name', 'api');
        });

        switch ($message->audience_type) {
            case Message::AUDIENCE_GLOBAL:
                // Todos los users humanos del sistema. No filtramos por super:
                // los otros super tambien deberian poder leer anuncios globales.
                break;

            case Message::AUDIENCE_TENANT:
                if (!$message->audience_id) return [];
                $query->where('tenant_id', $message->audience_id);
                break;

            case Message::AUDIENCE_USER:
                if (!$message->audience_id) return [];
                $query->where('id', $message->audience_id);
                break;

            default:
                return [];
        }

        return $query->pluck('id')->all();
    }

    /**
     * Marca el mensaje como leido para el user dado. No-op si el user no es
     * recipient (defensivo: evita crear fila fantasma).
     */
    public function markAsRead(User $user, Message $message): void
    {
        MessageRecipient::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Marca como leidos TODOS los mensajes no leidos del user.
     * Util para el boton "Marcar todo como leido" del inbox.
     *
     * @return int filas actualizadas.
     */
    public function markAllAsRead(User $user): int
    {
        return MessageRecipient::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Cantidad de mensajes no leidos del user, considerando solo mensajes
     * activos, publicados y no vencidos. Lo consume el bell de notificaciones.
     *
     * Acepta `int $userId` o el modelo `User`. Aceptar el id directo permite
     * llamarlo desde el middleware del bell sin precargar el modelo (~1 query
     * menos por polling, importante al escala con muchos usuarios activos).
     */
    public function unreadCountForUser(User|int $user): int
    {
        $userId = $user instanceof User ? $user->id : $user;

        return MessageRecipient::query()
            ->where('message_recipients.user_id', $userId)
            ->whereNull('message_recipients.read_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('messages')
                    ->whereColumn('messages.id', 'message_recipients.message_id')
                    ->whereNull('messages.deleted_at')
                    ->where('messages.is_active', true)
                    ->whereNotNull('messages.published_at')
                    ->where(function ($qq) {
                        $qq->whereNull('messages.expires_at')
                           ->orWhere('messages.expires_at', '>', now());
                    });
            })
            ->count();
    }

    /**
     * Query base del inbox del user: mensajes activos, publicados, no vencidos,
     * con join a recipients para exponer read_at por fila.
     *
     * Acepta `int $userId` o el modelo `User` (ver `unreadCountForUser`).
     */
    public function inboxFor(User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return Message::query()
            ->select('messages.*', 'message_recipients.read_at as read_at')
            ->join('message_recipients', 'message_recipients.message_id', '=', 'messages.id')
            ->where('message_recipients.user_id', $userId)
            ->whereNull('messages.deleted_at')
            ->where('messages.is_active', true)
            ->whereNotNull('messages.published_at')
            ->where(function ($q) {
                $q->whereNull('messages.expires_at')
                  ->orWhere('messages.expires_at', '>', now());
            })
            ->orderByDesc('messages.published_at');
    }
}
