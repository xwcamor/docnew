<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RuleCondition — una condición (variable + operador + valor).
 * Aquí viven los >, <, >=, <=, =, != . Varias condiciones de una regla = AND.
 *
 *   "H2 <= 150"        -> operator '<=', value 150
 *   "150 < H2 <= 200"  -> dos filas: ('>',150) y ('<=',200)
 */
class RuleCondition extends Model
{
    protected $fillable = ['rule_id', 'variable_id', 'operator', 'value', 'sort_order'];
    protected $casts = ['value' => 'decimal:4'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    /** Evalúa esta condición contra un valor medido. */
    public function matches(float $measured): bool
    {
        $v = (float) $this->value;
        return match ($this->operator) {
            '<'  => $measured <  $v,
            '<=' => $measured <= $v,
            '>'  => $measured >  $v,
            '>=' => $measured >= $v,
            '='  => $measured == $v,
            '!=' => $measured != $v,
            default => false,
        };
    }
}
