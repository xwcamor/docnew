<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Download;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * NotificationController
 *
 * Bandeja unificada de notificaciones del usuario: archivos exportados
 * (`downloads`) MÁS las notificaciones de la tabla `notifications` estándar
 * de Laravel (avisos de seguridad, automatizaciones, respuestas a mensajes…).
 * Cada entrada lleva un `kind` para que la pantalla sepa cómo pintarla.
 *
 * Las dos fuentes son las MISMAS que alimentan la campana del AppLayout
 * (`InboxService`): si aquí se dejara fuera una de ellas, la campana avisaría
 * de algo que la página "ver todas" no enseña.
 */
class NotificationController extends Controller
{
    /**
     * Lista todas las notificaciones activas del usuario, paginadas.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10;

        $items = collect($this->downloadItems())
            ->concat($this->appItems())
            ->sortByDesc(fn ($n) => $n['created_at'] ?? '')
            ->values();

        // Paginación en memoria: las dos fuentes viven en tablas distintas y
        // el volumen por usuario es de decenas de filas, no de miles.
        $page  = max(1, (int) $request->get('page', 1));
        $paged = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values()->all(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return inertia('Notifications/Index', [
            'notifications' => $paged->toArray(),
            'filters'       => [
                'per_page' => $perPage,
            ],
        ]);
    }

    /** Archivos exportados que siguen vigentes. */
    protected function downloadItems(): array
    {
        return Download::where('user_id', Auth::id())
            ->where('expires_at', '>=', now())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($d) => [
                'id'            => (string) $d->id,
                'slug'          => $d->slug,
                'kind'          => 'download',
                'type'          => $d->type,    // excel / pdf / word
                'filename'      => $d->filename,
                'status'        => $d->status,
                'created_at'    => $d->created_at?->toIso8601String(),
                'downloaded_at' => $d->downloaded_at?->toIso8601String(),
                'expires_at'    => $d->expires_at?->toIso8601String(),
                'error_message' => $d->error_message,
            ])
            ->all();
    }

    /**
     * Notificaciones de la tabla `notifications` (las que dispara
     * $user->notify() por el canal database). Sin esto la página quedaba
     * vacía aunque la campana marcara avisos sin leer.
     */
    protected function appItems(): array
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', Auth::id())
            ->orderByDesc('created_at')
            ->get(['id', 'type', 'data', 'read_at', 'created_at'])
            ->map(function ($n) {
                $data = json_decode($n->data, true) ?? [];

                return [
                    'id'         => "app-{$n->id}",
                    'raw_id'     => $n->id,
                    'kind'       => 'app',
                    'type'       => $data['category'] ?? class_basename($n->type),
                    'title'      => $data['title'] ?? class_basename($n->type),
                    'body'       => $data['body'] ?? '',
                    'channel'    => $data['channel'] ?? null,
                    'status'     => $n->read_at ? 'read' : 'unread',
                    'created_at' => $this->iso($n->created_at),
                    'read_at'    => $this->iso($n->read_at),
                ];
            })
            ->all();
    }

    protected function iso($value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Descarga el archivo generado (solo si está ready y no expiró).
     *
     * El bell del AppLayout pasa IDs prefijados ("dl-4") porque el inbox
     * mezcla downloads + database notifications con un discriminator. La
     * página /notifications/index pasa IDs numéricos crudos. Aceptamos ambos.
     */
    public function download($id)
    {
        $rawId = $this->parseDownloadId($id);
        if ($rawId === null) abort(404);

        $download = Download::where('user_id', Auth::id())
            ->where('status', 'ready')
            ->where('expires_at', '>=', now())
            ->findOrFail($rawId);

        $download->markAsDownloaded();

        return Storage::disk($download->disk)->download($download->path, $download->filename);
    }

    /**
     * Quitar una notificación de la bandeja del usuario.
     *
     * Acepta tres formatos de id:
     *   - "4"        → Download id numérico crudo (página /notifications)
     *   - "dl-4"     → Download prefijado (bell del AppLayout)
     *   - "app-UUID" → Database notification (bell — Automations, etc.)
     *
     * Para downloads físicamente borramos record + archivo. Para notifs
     * estándar de Laravel solo borramos el record (no tienen archivo).
     */
    public function delete($id)
    {
        if (str_starts_with($id, 'app-')) {
            return $this->deleteAppNotification(substr($id, 4));
        }

        $rawId = $this->parseDownloadId($id);
        if ($rawId === null) abort(404);

        $download = Download::where('user_id', Auth::id())->findOrFail($rawId);

        if ($download->path && Storage::disk($download->disk)->exists($download->path)) {
            Storage::disk($download->disk)->delete($download->path);
        }
        $download->delete();

        // back() en lugar de redirect()->route() — desde el bell el usuario
        // está en cualquier página y no espera navegar; desde /notifications
        // back() vuelve a la misma página con el flash. Cubre ambos casos.
        return back()->with('success', __('global.deleted_success'));
    }

    /**
     * Strip del prefijo "dl-" si viene del bell. Devuelve el id numérico
     * o null si no es un id válido de Download.
     */
    protected function parseDownloadId(string $id): ?int
    {
        $raw = str_starts_with($id, 'dl-') ? substr($id, 3) : $id;
        return ctype_digit($raw) ? (int) $raw : null;
    }

    /** Borra una database notification (tabla `notifications` de Laravel). */
    protected function deleteAppNotification(string $uuid)
    {
        $notification = Auth::user()->notifications()->where('id', $uuid)->first();
        if ($notification) $notification->delete();

        return back()->with('success', __('global.deleted_success'));
    }

    /**
     * Marca una notificación de la tabla estándar como leída. El id es el
     * UUID generado por Laravel Notifications (no el id numérico de downloads).
     */
    public function markAppRead(Request $request, string $id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->where('id', $id)->first();
        if ($notification && is_null($notification->read_at)) {
            $notification->markAsRead();
        }
        return back();
    }

    /** Marca TODAS las notificaciones app del usuario como leídas. */
    public function markAllAppRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
        return back();
    }
}
