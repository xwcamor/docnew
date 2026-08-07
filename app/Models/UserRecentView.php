<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserRecentView — historial de "últimos vistos" polimórfico.
 *
 * Sin updated_at; usamos viewed_at como el "tocado más reciente".
 */
class UserRecentView extends Model
{
    protected $fillable = ['user_id', 'viewable_type', 'viewable_id', 'viewed_at'];

    public $timestamps = false;
    protected $casts = ['viewed_at' => 'datetime'];

    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registrá una vista. Si ya existe ese (user, type, id) actualiza el
     * timestamp; si no, crea. Después poda dejando solo los últimos N por
     * (user, type) — hoy hardcodeado a 10, ajustable.
     */
    public static function track(int $userId, string $type, int $id, ?int $keep = null): void
    {
        // Default desde config si no se pasa explícito (10 por defecto).
        $keep = $keep ?? (int) config('regions.recent_views_keep', 10);
        // Upsert manual: como SQLite no soporta updateOrCreate con todos los
        // adapters de la misma forma, hacemos firstOrNew y save. Es 1 read +
        // 1 write máximo, despreciable a escala.
        $row = static::firstOrNew([
            'user_id'       => $userId,
            'viewable_type' => $type,
            'viewable_id'   => $id,
        ]);
        $row->viewed_at = now();
        $row->save();

        // Cleanup: dejamos solo los últimos $keep por (user, type).
        $stale = static::where('user_id', $userId)
            ->where('viewable_type', $type)
            ->orderByDesc('viewed_at')
            ->offset($keep)
            ->limit(1000)  // safety
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            static::whereIn('id', $stale)->delete();
        }
    }
}
