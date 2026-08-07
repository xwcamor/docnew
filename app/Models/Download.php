<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Download extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'filename',
        'path',
        'disk',
        'user_id',
        'status',
        'error_message',
        'expires_at',
        'downloaded_at',
        'slug',
    ];

    // $dates fue deprecado en Laravel 9+; ahora se usa $casts. Sin esto,
    // los timestamps vuelven como string crudo y romperían cualquier
    // ->toIso8601String() / ->format() en el frontend.
    protected $casts = [
        'expires_at'    => 'datetime',
        'downloaded_at' => 'datetime',
    ];

    /**
     * Calcula el `expires_at` para un Download recién creado leyendo el
     * Setting `downloads.expire_after_hours` (editable desde la UI sin redeploy).
     * Fallback a 24 horas si el setting no existe.
     */
    public static function computeExpiresAt(): \Carbon\Carbon
    {
        return now()->addHours(Setting::getInt('downloads.expire_after_hours', 24));
    }

    /**
     * Automatically generate a unique slug when creating a record + dispatch
     * email notifications when status transitions to ready/failed.
     *
     * El email es complementario a la bell in-app: la bell muestra el item
     * directo desde la tabla `downloads` (ver buildInboxPayload), el email
     * cubre el caso "el usuario no tiene la pestaña abierta".
     */
    protected static function booted()
    {
        static::creating(function ($download) {
            if (empty($download->slug)) {
                do {
                    $slug = Str::random(22);
                } while (Download::where('slug', $slug)->exists());

                $download->slug = $slug;
            }
        });

        static::updated(function (Download $download) {
            // Solo notificamos en transiciones reales (ready/failed) — no en
            // updates de path/error_message del mismo status.
            if (!$download->wasChanged('status')) return;

            $user = $download->user;
            if (!$user) return;

            try {
                if ($download->status === 'ready') {
                    $user->notify(new \App\Notifications\DownloadReady($download));
                } elseif ($download->status === 'failed') {
                    $user->notify(new \App\Notifications\DownloadFailed($download));
                }
            } catch (\Throwable $e) {
                // No queremos que un fallo de mail rompa el flujo del export.
                // Loggeamos y seguimos — la bell sigue mostrando el estado.
                \Log::warning('Download notification failed', [
                    'download_id' => $download->id,
                    'status'      => $download->status,
                    'error'       => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Relationship: owner of the download.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: active downloads (not expired and ready).
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'ready')
                     ->where('expires_at', '>=', Carbon::now());
    }

    /**
     * Scope: expired downloads.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
                     ->orWhere('expires_at', '<', Carbon::now());
    }

    /**
     * Mark a download as used (when the user downloads it).
     */
    public function markAsDownloaded(): void
    {
        $this->update([
            'downloaded_at' => now(),
        ]);
    }

    // Accessor: return HTML with icon and label for the file type
    public function getTypeHtmlAttribute(): string
    {
        return match ($this->type) {
            'excel' => '<i class="fas fa-file-excel text-success"></i> Excel',
            'word'  => '<i class="fas fa-file-word text-primary"></i> Word',
            'csv'   => '<i class="fas fa-file-csv text-info"></i> CSV',
            'pdf'   => '<i class="fas fa-file-pdf text-danger"></i> PDF',
            default => '<i class="fas fa-file text-secondary"></i> ' . strtoupper($this->type),
        };
    }
}