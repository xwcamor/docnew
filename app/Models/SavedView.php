<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SavedView — vista guardada (snapshot de filtros + columnas + sort) que un
 * usuario puede aplicar a un listado con un click.
 *
 * Una sola tabla sirve para TODOS los módulos: el campo `module` discrimina.
 *
 * Constraint: solo una vista `is_default = true` por usuario y módulo. Eso
 * está enforced a nivel BD en Postgres (partial unique index) y a nivel app
 * en el controller (defensa en otros drivers).
 */
class SavedView extends Model
{
    protected $table = 'user_saved_views';

    protected $fillable = [
        'user_id',
        'module',
        'name',
        'is_default',
        'state',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'state'      => 'array',
    ];

    // ─── Relaciones ──────────────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─── Scopes ─────────────────────────────────────────────────────────────
    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }

    public function scopeForModule(Builder $q, string $module): Builder
    {
        return $q->where('module', $module);
    }
}
