<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Slot de firma de los informes de un workspace (Supervisor, Auditor, …).
 * user_id null = firmante externo (solo nombre impreso, firma a mano).
 * El nombre impreso sale del usuario si hay; si no, del campo name.
 */
class ReportSigner extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'name', 'title', 'relation', 'sort_order'];

    /** Relaciones válidas (claves traducibles) firmante↔informe. */
    public const RELATIONS = ['prepared', 'reviewed', 'approved', 'authorized', 'verified', 'endorsed'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Nombre impreso bajo la línea de firma. */
    public function displayName(): ?string
    {
        return $this->user?->name ?: $this->name;
    }
}
