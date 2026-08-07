<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Test — catálogo de pruebas de diagnóstico (cromas, fiquis, furanos…).
 * `hi_weight` / `hi_enabled` controlan su participación en el Índice de Salud.
 */
class Test extends Model
{
    protected $fillable = [
        'code', 'name', 'hi_weight', 'hi_enabled', 'is_active', 'sort_order',
    ];
    protected $casts = [
        'hi_enabled' => 'boolean',
        'is_active'  => 'boolean',
        'hi_weight'  => 'integer',
    ];

    public function ruleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class);
    }
}
