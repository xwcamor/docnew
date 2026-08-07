<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * DiagnosticDataset — un set de datos de diagnóstico editable (ver migración).
 * `data` es el JSON completo (mismo formato que el archivo de origen). Lo lee el
 * motor vía RuleData::get(); lo edita la UI de Reglas de Diagnóstico.
 */
class DiagnosticDataset extends Model
{
    use Auditable;

    protected string $auditModule = 'diagnostic_rules';

    protected $fillable = ['key', 'tenant_id', 'label', 'data', 'created_by', 'updated_by'];

    protected $casts = ['data' => 'array'];
}
