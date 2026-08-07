<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * NotificationController
 *
 * Bandeja unificada de notificaciones del usuario. Por ahora solo expone
 * "downloads" (archivos generados por exports), pero la idea es que crezca
 * para incluir tareas pendientes, alertas, mensajes, etc. Cuando se agreguen
 * más tipos, este controller los unifica en `index()` con un campo `type`
 * por entrada.
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

        // Por ahora la bandeja = sus downloads activos. Cuando se sumen
        // tareas/alertas, se mergea aquí con un type discriminator.
        $downloads = Download::where('user_id', Auth::id())
            ->where('expires_at', '>=', now())
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $payload = $downloads->toArray();
        $payload['data'] = collect($downloads->items())->map(fn ($d) => [
            'id'            => $d->id,
            'slug'          => $d->slug,
            'kind'          => 'download',  // Discriminator: download | task | alert (futuro)
            'type'          => $d->type,    // Solo aplica a downloads (excel/pdf/word)
            'filename'      => $d->filename,
            'status'        => $d->status,
            'created_at'    => $d->created_at,
            'downloaded_at' => $d->downloaded_at,
            'expires_at'    => $d->expires_at,
            'error_message' => $d->error_message,
        ])->all();

        return inertia('Notifications/Index', [
            'notifications' => $payload,
            'filters'       => [
                'per_page' => $perPage,
            ],
        ]);
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
