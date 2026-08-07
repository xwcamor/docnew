<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MessageReply — respuesta plana (sin threading) a un Message con allow_replies.
 *
 * Tanto el super (creador) como los recipients pueden responder, en orden
 * cronologico. El frontend renderiza el listado por created_at ASC.
 */
class MessageReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'user_id',
        'body',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        // `withoutGlobalScopes` bypassea HideSuperScope: si el que respondio es
        // super (replica al propio mensaje suyo), los recipients no-super
        // verian null en `user` sin este bypass. Mismo razonamiento que
        // Message::creator().
        return $this->belongsTo(User::class)
            ->withTrashed()
            ->withoutGlobalScopes();
    }
}
