<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * IdempotencyKey — la respuesta guardada de una petición que modifica datos.
 *
 * No es un módulo ni tiene UI: es infraestructura de la API. Sin soft-delete
 * (una clave vencida se borra de verdad, no hay nada que restaurar) y sin
 * tenant scope (la aísla el token, que ya pertenece a un workspace).
 *
 * Ver App\Http\Middleware\EnforceIdempotency para el ciclo completo.
 */
class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key', 'token_id', 'endpoint', 'request_hash',
        'response_status', 'response_body', 'resource_id',
        'created_at', 'expires_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
