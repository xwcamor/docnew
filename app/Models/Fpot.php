<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fpot — una muestra de Factor de Potencia del aislamiento (num_fac, en %).
 *
 * Test de valor único: el diagnóstico sale de ubicar `value` en la escala de
 * fpot (result_scales, DATOS editables) → condición/color/rating. La escala es
 * la del código viejo (factor.rb): <0.5 Muy Bueno (4) … ≥2 Muy Malo (0).
 */
class Fpot extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant;

    protected string $auditModule = 'fpot';

    protected $fillable = [
        'transformer_id', 'sample_date', 'report_number', 'laboratory_id',
        'value', 'temperature',
        'rating', 'condition',
        'tenant_id', 'created_by', 'deleted_by',
    ];

    protected $casts = [
        'sample_date' => 'datetime',
        'value'       => 'decimal:3',
        'temperature' => 'decimal:2',
        'rating'      => 'decimal:1',
    ];

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Laboratory::class);
    }
}
