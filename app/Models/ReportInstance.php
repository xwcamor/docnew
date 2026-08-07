<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Instancia de informe oficial bajo flujo de aprobación (etapa 2 de firmas).
 * Congela un snapshot de los datos del informe; los firmantes aprueban eso.
 * Estados: in_review | approved | rejected.
 */
class ReportInstance extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'report_request_id', 'transformer_id', 'preparer_id', 'status',
        'report_code', 'verify_code', 'snapshot', 'issued_at',
        'frozen_path', 'frozen_hash', 'frozen_at',
    ];

    protected $casts = [
        'snapshot'  => 'array',
        'issued_at' => 'datetime',
        'frozen_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function request()
    {
        return $this->belongsTo(ReportRequest::class, 'report_request_id');
    }

    public function transformer()
    {
        return $this->belongsTo(Transformer::class);
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'preparer_id');
    }

    public function approvals()
    {
        return $this->hasMany(ReportApproval::class)->orderBy('id');
    }

    public function isIssued(): bool
    {
        return $this->status === 'approved';
    }

    /** Todos los firmantes internos aprobaron → listo para emitir. */
    public function allApproved(): bool
    {
        return $this->approvals()->where('status', '!=', 'approved')->doesntExist();
    }
}
