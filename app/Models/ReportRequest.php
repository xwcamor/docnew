<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Solicitud de aprobación de informes (etapa 2 de firmas) — la UNIDAD que se
 * aprueba. Agrupa N informes (1 trafo o flota). Los firmantes aprueban la
 * solicitud UNA vez → se aprueban todos sus informes de golpe.
 *
 * Si nació de un "compartir", lleva los datos del envío y al aprobarse activa
 * el share (auto-compartir). Estados: in_review | approved | rejected.
 */
class ReportRequest extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'requester_id', 'scope', 'customer_id', 'label', 'status',
        'recipient_email', 'expiration_days', 'locale', 'report_share_id', 'issued_at',
    ];

    protected $casts = [
        'issued_at'       => 'datetime',
        'expiration_days' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function instances()
    {
        return $this->hasMany(ReportInstance::class)->orderBy('id');
    }

    public function approvals()
    {
        return $this->hasMany(ReportApproval::class)->orderBy('id');
    }

    public function share()
    {
        return $this->belongsTo(ReportShare::class, 'report_share_id');
    }

    public function isIssued(): bool
    {
        return $this->status === 'approved';
    }

    public function isFleet(): bool
    {
        return $this->scope === 'fleet';
    }

    /** Todos los firmantes aprobaron → la solicitud está lista para emitir. */
    public function allApproved(): bool
    {
        return $this->approvals()->where('status', '!=', 'approved')->doesntExist();
    }
}
