<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aprobación por firmante de una instancia de informe (etapa 2 de firmas).
 * La firma se estampa al aprobar = consentimiento puntual auditado.
 * Estados: pending | approved | rejected.
 */
class ReportApproval extends Model
{
    protected $fillable = [
        'report_request_id', 'report_instance_id', 'report_signer_id', 'user_id',
        'title', 'relation', 'status', 'signature', 'reason', 'acted_at',
    ];

    protected $casts = [
        'acted_at' => 'datetime',
    ];

    public function reportRequest()
    {
        return $this->belongsTo(ReportRequest::class, 'report_request_id');
    }

    public function reportInstance()
    {
        return $this->belongsTo(ReportInstance::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function signer()
    {
        return $this->belongsTo(ReportSigner::class, 'report_signer_id');
    }
}
