<?php

namespace App\Traits;

use App\Models\UserFavorite;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Favoritos polimórficos. Uso:
 *   $region->isFavoritedBy($userId)
 *   Region::query()->orderByFavoriteFirst($userId)->get()  // pinea favs arriba
 */
trait HasFavorites
{
    public function favoritedBy(): MorphMany
    {
        return $this->morphMany(UserFavorite::class, 'favoritable');
    }

    public function isFavoritedBy(?int $userId): bool
    {
        if (!$userId) return false;
        return $this->favoritedBy()->where('user_id', $userId)->exists();
    }

    /**
     * Pinea favoritos arriba + expone is_favorite como columna calculada
     * en la misma query (sin N+1). Sin user → is_favorite siempre false.
     */
    public function scopeOrderByFavoriteFirst(Builder $query, ?int $userId): Builder
    {
        $table = $this->getTable();
        $morph = static::class;

        if (!$userId) {
            return $query->addSelect(\DB::raw('FALSE AS is_favorite'));
        }

        return $query
            ->leftJoin('user_favorites', function ($join) use ($table, $morph, $userId) {
                $join->on('user_favorites.favoritable_id', '=', "{$table}.id")
                     ->where('user_favorites.favoritable_type', '=', $morph)
                     ->where('user_favorites.user_id', '=', $userId);
            })
            ->addSelect(\DB::raw('(user_favorites.id IS NOT NULL) AS is_favorite'))
            ->orderByRaw('CASE WHEN user_favorites.id IS NULL THEN 1 ELSE 0 END');
    }
}
