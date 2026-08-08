<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nationality extends Model
{
    use HasFactory;
    use Auditable;
    use SoftDeletes;
    use BelongsToTenantOrGlobal;

    // `code` es el texto que se lee en la ficha de la persona —«Peruana»,
    // «Venezolana»—, no una clave interna, y faltaba en esta lista: el sembrado
    // de la demo se lo pasaba a `create()` y Eloquent lo tiraba en silencio, con
    // lo que la unica nacionalidad que existia salia en blanco en el selector.
    protected $fillable = [
        'slug', 'country_id', 'code', 'is_active',
        'tenant_id', 'created_by', 'deleted_by', 'deleted_description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
