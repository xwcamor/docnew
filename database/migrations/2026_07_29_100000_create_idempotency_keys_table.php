<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotencia de la API: guarda la respuesta de cada petición que MODIFICA
 * datos, indexada por la cabecera `Idempotency-Key` del cliente.
 *
 * Existe por un caso concreto: el laboratorio manda un resultado, la respuesta
 * se pierde por timeout y el reintento inserta la muestra OTRA VEZ. El índice
 * `(transformer_id, sample_date)` de las tablas de muestras NO es único, así
 * que nada lo impedía. Con esta tabla el reintento devuelve la respuesta
 * original y no crea nada.
 *
 * Notas de diseño:
 *  - `token_id` en la clave única: dos workspaces pueden usar el mismo uuid sin
 *    pisarse. 0 = petición sin token (sesión web), que hoy no ocurre pero deja
 *    la columna NOT NULL y el índice único utilizable (en Postgres dos NULL son
 *    distintos y el índice no serviría de nada).
 *  - `request_hash`: sha256 del cuerpo. Misma clave + cuerpo distinto = el
 *    cliente reusó la clave para otra cosa → 409, nunca se pisa lo guardado.
 *  - La fila se escribe DENTRO de la misma transacción que los datos: si el
 *    guardado falla, la clave tampoco queda — el reintento vuelve a intentarlo
 *    limpio en vez de replicar un error.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191);
            $table->unsignedBigInteger('token_id')->default(0);
            $table->string('endpoint', 191);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable(); // trazabilidad: qué recurso creó
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();

            $table->unique(['token_id', 'key'], 'idempotency_keys_token_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
