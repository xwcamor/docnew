<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Furano — una muestra de análisis de furanos (compuestos furánicos en ppb).
 *
 * El diagnóstico depende de `fal` (2-furfuraldehído). El rating del Índice de
 * Salud sale de fal convertido a ppm (fal/1000) ubicado en la escala de furanos.
 * La conversión ÷1000 es FÓRMULA (código); los umbrales viven en result_scales.
 */
class Furano extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant;

    protected string $auditModule = 'furanos';

    protected $fillable = [
        'transformer_id', 'sample_date', 'report_number', 'laboratory_id',
        'fal', 'hme', 'ace', 'mfu', 'fua', 'dp',
        'rating', 'condition',
        'tenant_id', 'created_by', 'deleted_by',
    ];

    protected $casts = [
        'sample_date' => 'datetime',
        'fal' => 'decimal:3', 'hme' => 'decimal:3', 'ace' => 'decimal:3',
        'mfu' => 'decimal:3', 'fua' => 'decimal:3',
        'dp'  => 'integer',
        'rating' => 'decimal:1',
    ];

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Laboratory::class);
    }

    /** 2-furfuraldehído en ppm (los umbrales de la escala están en ppm). Null si no se midió. */
    public function falPpm(): ?float
    {
        return $this->fal === null ? null : ((float) $this->fal) / 1000.0;
    }
}
