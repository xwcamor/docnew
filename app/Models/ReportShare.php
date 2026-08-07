<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * ReportShare — enlace público para compartir un diagnóstico (un trafo o la
 * flota de un cliente) con expiración y acceso por OTP. Ver docs/COMPARTIR-REPORTES.md.
 *
 * NO usa BelongsToTenant: el acceso es público (sin auth). La seguridad se basa
 * en el token + OTP + expiración, y en leer SIEMPRE por transformer_id/customer_id
 * de este share (nunca queries abiertas a un tenant).
 */
class ReportShare extends Model
{
    protected $fillable = [
        'token', 'scope_type', 'transformer_id', 'customer_id', 'transformer_ids', 'tenant_id',
        'recipient_email', 'note', 'locale', 'expires_at', 'revoked_at',
        'last_opened_at', 'open_count', 'created_by',
    ];

    protected $casts = [
        'transformer_ids' => 'array',
        'expires_at'     => 'datetime',
        'revoked_at'     => 'datetime',
        'last_opened_at' => 'datetime',
        'open_count'     => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ReportShare $share) {
            if (empty($share->token)) {
                $share->token = self::newToken();
            }
        });
    }

    public static function newToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    /** Activo = no revocado y no vencido. */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function isFleet(): bool
    {
        return $this->scope_type === 'fleet';
    }

    /**
     * Enlace sin destinatario: lo reparte el usuario por su cuenta. Sin correo
     * no hay OTP, así que el token es la única credencial (sigue venciendo).
     */
    public function isLinkOnly(): bool
    {
        return blank($this->recipient_email);
    }

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Audita el compartir (1 trafo o flota) en el módulo `transformers`, para que
     * super/admin lo vean en el ledger. Se llama desde ambos caminos de creación
     * (compartir directo y auto-compartir tras aprobación).
     */
    public function auditShared(): void
    {
        \App\Models\AuditLog::create([
            'user_id'        => auth()->id() ?? $this->created_by,
            'event'          => 'report_shared',
            'auditable_type' => \App\Models\Transformer::class,
            'auditable_id'   => $this->transformer_id,
            'module'         => 'transformers',
            'old_values'     => null,
            'new_values'     => [
                'scope_type'      => $this->scope_type,
                'recipient_email' => $this->recipient_email,
                'customer_id'     => $this->customer_id,
                'transformer_id'  => $this->transformer_id,
                'fleet_count'     => is_array($this->transformer_ids) ? count($this->transformer_ids) : null,
                'expires_at'      => optional($this->expires_at)->toDateTimeString(),
                'share_id'        => $this->id,
            ],
            'url'        => null,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * Revocar corta el acceso pero NO borra: la fila queda con su historial
     * (aperturas, qué se envió). Se audita igual que la emisión — quién cortó
     * el acceso a un informe ya entregado es tan auditable como haberlo dado.
     */
    public function auditRevoked(): void
    {
        \App\Models\AuditLog::create([
            'user_id'        => auth()->id(),
            'event'          => 'report_share_revoked',
            'auditable_type' => \App\Models\Transformer::class,
            'auditable_id'   => $this->transformer_id,
            'module'         => 'transformers',
            'old_values'     => null,
            'new_values'     => [
                'scope_type'      => $this->scope_type,
                'recipient_email' => $this->recipient_email,
                'customer_id'     => $this->customer_id,
                'transformer_id'  => $this->transformer_id,
                'fleet_count'     => is_array($this->transformer_ids) ? count($this->transformer_ids) : null,
                'open_count'      => $this->open_count,
                'share_id'        => $this->id,
            ],
            'url'        => null,
            'ip_address' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }
}
