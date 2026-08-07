<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Subscription — período de plan pagado/trial de un tenant.
 *
 * Una fila por período. NUNCA se mutan starts_at/ends_at/plan — si cambia el
 * plan, se crea fila nueva. Esto preserva el histórico para reportes y
 * compliance ("Héctor pagó hasta agosto").
 *
 * El plan "actual" de un tenant = sub con status in (trial, active) AND
 * ends_at > now(). Si ninguna match → tenant sin plan activo (bloqueado).
 */
class Subscription extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected string $auditModule = 'subscriptions';

    public const STATUS_TRIAL     = 'trial';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUSES = [
        self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_EXPIRED,
        self::STATUS_SUSPENDED, self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'tenant_id',
        'plan',
        'status',
        'starts_at', 'ends_at', 'trial_ends_at',
        'cancelled_at', 'cancellation_reason',
        'amount_paid', 'currency', 'payment_method',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'starts_at'     => 'datetime',
        'ends_at'       => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancelled_at'  => 'datetime',
        'amount_paid'   => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    /**
     * Subs vigentes — el tenant todavía tiene acceso al plan.
     *
     * trial/active: obvio. cancelled: sigue vigente hasta `ends_at` — el
     * "cancel suave" (se canceló pero sigue activo hasta fin del período pagado).
     * suspended: NO (corte inmediato). expired: NO.
     */
    public function scopeCurrent(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_CANCELLED])
                 ->where('ends_at', '>', now());
    }

    /** Subs que pasaron `ends_at` y siguen marcadas como trial/active (cron las marca expired). */
    public function scopeJustExpired(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_TRIAL, self::STATUS_ACTIVE])
                 ->where('ends_at', '<=', now());
    }

    /** Subs que vencen en próximos N días — para mandar warning emails. */
    public function scopeExpiringIn(Builder $q, int $days): Builder
    {
        return $q->current()
                 ->where('ends_at', '<=', now()->addDays($days));
    }

    // ─── Estado computado ────────────────────────────────────────────────────

    public function isCurrent(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_CANCELLED], true)
            && $this->ends_at && $this->ends_at->isFuture();
    }

    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIAL && $this->isCurrent();
    }

    /**
     * Cancelada pero todavía vigente — el "cancel suave". El tenant ya canceló
     * pero conserva el acceso hasta `ends_at`.
     */
    public function isCancelledButActive(): bool
    {
        return $this->status === self::STATUS_CANCELLED && $this->isCurrent();
    }

    /**
     * Expirada = se acabó por vencimiento natural. Cubre `status=expired` y el
     * caso trial/active que ya pasó `ends_at` sin que el cron la marque todavía.
     *
     * `cancelled` y `suspended` NO son "expired" — son estados terminales
     * propios (cancelación / corte deliberado), aunque hayan pasado su fecha.
     */
    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true)
            && $this->ends_at && $this->ends_at->isPast();
    }

    public function daysRemaining(): int
    {
        if (!$this->ends_at || $this->ends_at->isPast()) return 0;
        return (int) now()->diffInDays($this->ends_at, false);
    }

    // NOTA: no hay hook de sync a `tenants.plan` — esa columna no existe. El
    // plan del tenant se deriva en vivo de la suscripción vigente vía
    // Tenant::currentPlan(). No hay snapshot que mantener.
}
