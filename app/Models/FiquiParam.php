<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * FiquiParam — un parámetro fisicoquímico (rig, ten, … o uno custom como color).
 * Su definición (peso, dirección, unidad, método, modo) vive en BD; los umbrales
 * por aceite/clase están en fiqui_thresholds.
 */
class FiquiParam extends Model
{
    use Auditable;

    protected string $auditModule = 'fiqui_params';

    protected $fillable = [
        'key', 'label', 'weight', 'dir', 'unit', 'astm', 'mode', 'tol',
        'scored', 'display_order', 'is_core', 'is_active', 'created_by',
    ];

    protected $casts = [
        'weight'        => 'float',
        'tol'           => 'float',
        'scored'        => 'boolean',
        'display_order' => 'integer',
        'is_core'       => 'boolean',
        'is_active'     => 'boolean',
    ];

    public function thresholds()
    {
        return $this->hasMany(FiquiThreshold::class, 'param_key', 'key');
    }
}
