<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Standard — norma técnica (IEEE C57.104, IEC 60599…) con versión.
 * Da trazabilidad: cada diagnóstico sabe bajo qué norma se hizo.
 */
class Standard extends Model
{
    protected $fillable = ['code', 'name', 'version', 'description', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function ruleSets(): HasMany
    {
        return $this->hasMany(RuleSet::class);
    }
}
