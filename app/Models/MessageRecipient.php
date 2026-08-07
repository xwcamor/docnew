<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MessageRecipient — pivot materializado entre Message y User.
 *
 * Una fila por cada user que debe ver el mensaje. read_at se setea cuando el
 * user abre el detalle del mensaje (InboxController::show).
 */
class MessageRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'user_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
