<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rule — una regla dentro de un cuadro. Produce `score` (y aporta `weight`)
 * cuando TODAS sus condiciones se cumplen. `priority` ordena la evaluación.
 */
class Rule extends Model
{
    protected $fillable = [
        'rule_set_id', 'variable_id', 'score', 'weight', 'priority',
        'result_label', 'is_active',
    ];
    protected $casts = [
        'score'     => 'decimal:2',
        'weight'    => 'decimal:2',
        'priority'  => 'integer',
        'is_active' => 'boolean',
    ];

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(RuleCondition::class)->orderBy('sort_order');
    }
}
