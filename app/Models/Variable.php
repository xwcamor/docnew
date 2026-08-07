<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Variable — qué se mide o evalúa (H2, CH4, ratios, atributos…).
 * Son filas (no columnas) para poder agregar ejes nuevos sin tocar el esquema.
 */
class Variable extends Model
{
    protected $fillable = ['code', 'name', 'unit', 'kind', 'is_active', 'sort_order'];
    protected $casts = ['is_active' => 'boolean'];
}
