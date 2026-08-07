<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserFavorite — pivot polimórfico que une usuarios con cualquier modelo
 * marcado como favorito (regiones, idiomas, tenants, etc.).
 *
 * No tiene updated_at — los favoritos no se actualizan, se crean o eliminan.
 */
class UserFavorite extends Model
{
    protected $fillable = ['user_id', 'favoritable_type', 'favoritable_id'];

    public $timestamps = false;
    protected $casts = ['created_at' => 'datetime'];

    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
