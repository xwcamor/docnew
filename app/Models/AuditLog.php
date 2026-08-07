<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog — append-only ledger across the entire application.
 *
 * One row = one action performed by a user on an auditable record.
 * Use the {@see \App\Traits\Auditable} trait on any Eloquent model
 * to get automatic audit entries on created/updated/deleted/restored.
 */
class AuditLog extends Model
{
    /** @var string Logs are immutable — only insert, never update. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'event',
        'auditable_type',
        'auditable_id',
        'module',
        'old_values',
        'new_values',
        'url',
        'ip_address',
        'user_agent',
        'note',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
