<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * RuleSet — un "cuadro" de reglas para una combinación dada
 * (prueba + tipo de aceite + tipo de trafo + norma).
 *
 * Reemplaza los if anidados del sistema viejo: en vez de código, una fila.
 */
class RuleSet extends Model
{
    protected $fillable = [
        'tenant_id', 'test_id', 'oil_type_id', 'transformer_type_id', 'standard_id',
        'total_weight', 'label', 'is_active',
    ];
    protected $casts = [
        'total_weight' => 'decimal:2',
        'is_active'    => 'boolean',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function oilType(): BelongsTo
    {
        return $this->belongsTo(OilType::class);
    }

    public function transformerType(): BelongsTo
    {
        return $this->belongsTo(TransformerType::class);
    }

    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class)->orderBy('priority');
    }
}
