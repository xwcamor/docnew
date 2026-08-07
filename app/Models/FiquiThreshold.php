<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * FiquiThreshold — los umbrales de un parámetro para un aceite + clase de tensión.
 * Para modo 'scored': t1,t2,t3 son los cortes de las 4 bandas de score.
 * Para modo 'limit': solo t1 (umbral único).
 */
class FiquiThreshold extends Model
{
    use Auditable;

    protected string $auditModule = 'fiqui_params';

    protected $fillable = [
        'param_key', 'oil_code', 'voltage_class', 't1', 't2', 't3', 'tenant_id',
    ];

    protected $casts = [
        't1' => 'float',
        't2' => 'float',
        't3' => 'float',
    ];
}
