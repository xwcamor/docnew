<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ResultScale — el semáforo: a qué condición/color corresponde un score.
 * Editable y por norma. Ej: score < 1.2 -> "Muy Bueno", verde, rating 4.
 */
class ResultScale extends Model
{
    protected $fillable = [
        'tenant_id', 'test_id', 'standard_id', 'score_from', 'score_to',
        'condition_label', 'color', 'hi_rating', 'sort_order',
    ];
    protected $casts = [
        'score_from' => 'decimal:2',
        'score_to'   => 'decimal:2',
        'hi_rating'  => 'decimal:1',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    /** ¿Cae `score` dentro de este tramo? (from inclusivo, to exclusivo) */
    public function contains(float $score): bool
    {
        $from = $this->score_from === null ? -INF : (float) $this->score_from;
        $to   = $this->score_to   === null ?  INF : (float) $this->score_to;
        return $score >= $from && $score < $to;
    }
}
