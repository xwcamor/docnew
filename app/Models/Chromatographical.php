<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chromatographical — una muestra de cromatografía (los 9 gases medidos en ppm).
 *
 * El método gas() permite al motor leer un gas por su código de variable
 * ('h2', 'ch4'…) de forma uniforme, sin condicionales por cada gas.
 */
class Chromatographical extends Model
{
    use HasFactory, SoftDeletes, Auditable, BelongsToTenant;

    protected string $auditModule = 'chromatographicals';

    protected $fillable = [
        'transformer_id', 'sample_date', 'report_number', 'laboratory_id',
        'h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2',
        'dgaf_score', 'dgaf_condition',
        'tenant_id', 'created_by', 'deleted_by',
    ];

    protected $casts = [
        'sample_date' => 'datetime',
        'h2' => 'decimal:2', 'o2' => 'decimal:2', 'n2' => 'decimal:2',
        'ch4' => 'decimal:2', 'co' => 'decimal:2', 'co2' => 'decimal:2',
        'c2h4' => 'decimal:2', 'c2h6' => 'decimal:2', 'c2h2' => 'decimal:2',
        'dgaf_score' => 'decimal:4',
    ];

    /** Gases válidos en esta muestra (mapea código de variable -> columna). */
    public const GASES = ['h2', 'o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2'];

    public function transformer(): BelongsTo
    {
        return $this->belongsTo(Transformer::class);
    }

    public function laboratory(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Laboratory::class);
    }

    /** Lee el valor de un gas por su código (ej. 'h2'). Null si no se midió. */
    public function gas(string $code): ?float
    {
        if (!in_array($code, self::GASES, true)) {
            return null;
        }
        $v = $this->{$code};
        return $v === null ? null : (float) $v;
    }

    /** Devuelve solo los gases que tienen valor (para el motor). */
    public function measuredGases(): array
    {
        $out = [];
        foreach (self::GASES as $g) {
            if ($this->{$g} !== null) {
                $out[$g] = (float) $this->{$g};
            }
        }
        return $out;
    }
}
