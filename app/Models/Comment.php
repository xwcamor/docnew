<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Comment — comentario de usuario polimórfico (ver migración).
 *
 * Se adjunta a un transformer o a una muestra de prueba. El `body` es contenido
 * del usuario: NO se traduce, se muestra tal cual con su autor. El `context`
 * separa hilos sobre el mismo modelo (notas de diagnóstico por prueba, etc.).
 */
class Comment extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant;

    protected string $auditModule = 'comments';

    protected $fillable = [
        'commentable_type', 'commentable_id', 'context',
        'user_id', 'body', 'lang', 'tenant_id', 'created_by', 'deleted_by',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Autor del comentario. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
