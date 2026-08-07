<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

use App\Traits\Auditable;

/**
 * Message — anuncio/aviso/debate creado por super y dirigido a una audiencia.
 *
 * audience_type:
 *   - 'global' : todos los users humanos (excluye api/system users)
 *   - 'tenant' : todos los users del tenant cuyo id queda en audience_id
 *   - 'user'   : solo el user cuyo id queda en audience_id
 *
 * Al publicar (publish), el service materializa los recipients en la tabla
 * message_recipients. Esto evita recalcular la audiencia en cada lectura.
 */
class Message extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected string $auditModule = 'messages';

    protected $fillable = [
        'subject',
        'body',
        'created_by',
        'audience_type',
        'audience_id',
        'allow_replies',
        'is_active',
        'published_at',
        'expires_at',
    ];

    protected $casts = [
        'allow_replies' => 'boolean',
        'is_active'     => 'boolean',
        'published_at'  => 'datetime',
        'expires_at'    => 'datetime',
    ];

    // Constantes de audience_type para evitar strings sueltos por el codigo.
    public const AUDIENCE_GLOBAL = 'global';
    public const AUDIENCE_TENANT = 'tenant';
    public const AUDIENCE_USER   = 'user';

    protected static function booted(): void
    {
        static::creating(function ($message) {
            if (empty($message->slug)) {
                $attempts = 0;
                do {
                    $slug = Str::random(22);
                    $attempts++;
                } while ($attempts < 5 && Message::withTrashed()->where('slug', $slug)->exists());
                $message->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ─── Relaciones ──────────────────────────────────────────────────────────

    public function creator(): BelongsTo
    {
        // `withoutGlobalScopes` bypassea HideSuperScope: el creator de un
        // mensaje (siempre super) debe ser visible para el recipient (no-super).
        // Sin esto, Joe veria el mensaje pero la relacion creator() devuelve
        // null porque el scope filtra a Carlos.
        return $this->belongsTo(User::class, 'created_by')
            ->withTrashed()
            ->withoutGlobalScopes();
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageRecipient::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(MessageReply::class)->orderBy('created_at');
    }

    // ─── Helpers de estado ───────────────────────────────────────────────────

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * El mensaje esta "visible" en el inbox si: publicado, activo y no vencido.
     */
    public function isVisible(): bool
    {
        return $this->isPublished()
            && $this->is_active
            && !$this->isExpired();
    }
}
