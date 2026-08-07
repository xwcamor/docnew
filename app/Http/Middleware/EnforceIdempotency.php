<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceIdempotency — hace que reintentar una petición no duplique datos.
 *
 * Uso en routes:
 *   Route::middleware('idempotency')->post(...)            → cabecera obligatoria
 *   Route::middleware('idempotency:optional')->post(...)   → si viene, se respeta
 *
 * El problema que resuelve es real y hoy no tiene defensa: el laboratorio manda
 * un resultado, la respuesta se pierde por un timeout de red, su cola reintenta
 * y la muestra entra dos veces. Las tablas de muestras no tienen índice único
 * por fecha, así que nada lo frena.
 *
 * Ciclo:
 *
 *   1. Sin cabecera → 400 (salvo modo `optional`).
 *   2. La clave ya tiene respuesta guardada:
 *        · mismo cuerpo    → 200 con la respuesta ORIGINAL, sin ejecutar nada.
 *        · cuerpo distinto → 409: el cliente reusó la clave para otra cosa.
 *          Devolver la respuesta vieja sería mentirle sobre datos que no
 *          guardamos, y ejecutar sería duplicar.
 *   3. Clave nueva → se reserva la fila, se ejecuta la petición y la respuesta
 *      se guarda DENTRO de la misma transacción que los datos. O quedan las dos
 *      cosas o no queda ninguna.
 *   4. Si la petición falla (validación, error del motor), se libera la reserva:
 *      el laboratorio puede corregir el cuerpo y reintentar con la misma clave.
 *
 * Reserva sin respuesta = hay otra petición idéntica EN VUELO → 409, que es la
 * respuesta segura (reintentar más tarde devuelve la original). Si esa reserva
 * quedó huérfana por una caída del proceso, `STALE_MINUTES` la libera sola: sin
 * respuesta guardada la transacción de datos tampoco commiteó, así que no hay
 * nada duplicado que temer.
 */
class EnforceIdempotency
{
    /** Minutos tras los cuales una reserva sin respuesta se considera abandonada. */
    private const STALE_MINUTES = 10;

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            if ($mode === 'optional') {
                return $next($request);
            }

            return $this->fail(
                __('lab_api.idempotency_required'),
                400,
                'idempotency_key_required',
            );
        }

        if (mb_strlen($key) > 191) {
            return $this->fail(__('lab_api.idempotency_too_long'), 400, 'idempotency_key_invalid');
        }

        // El token identifica al workspace: dos laboratorios distintos pueden
        // usar el mismo uuid sin colisionar. Sin token real (sesión, o
        // Sanctum::actingAs en pruebas) queda 0, que es un dueño válido más.
        $token   = $request->user()?->currentAccessToken();
        $tokenId = (int) ($token instanceof PersonalAccessToken ? $token->id : 0);
        $hash    = hash('sha256', (string) $request->getContent());

        $existing = IdempotencyKey::where('token_id', $tokenId)->where('key', $key)->first();

        if ($existing) {
            $replay = $this->replayOrConflict($existing, $hash);
            if ($replay) {
                return $replay;
            }
            // Reserva abandonada: se libera y se sigue de largo como si fuera nueva.
            $existing->delete();
        }

        try {
            $claim = IdempotencyKey::create([
                'key'          => $key,
                'token_id'     => $tokenId,
                'endpoint'     => $request->method() . ' ' . $request->path(),
                'request_hash' => $hash,
                'created_at'   => now(),
                'expires_at'   => now()->addDays((int) config('lab_integration.idempotency_ttl_days', 30)),
            ]);
        } catch (QueryException) {
            // Carrera con otra petición de la misma clave: ganó la otra.
            return $this->inProgress();
        }

        DB::beginTransaction();

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::rollBack();
            $claim->delete();
            throw $e;
        }

        if ($response->getStatusCode() >= 400) {
            DB::rollBack();
            $claim->delete();

            return $response;
        }

        $claim->update([
            'response_status' => $response->getStatusCode(),
            'response_body'   => $response->getContent(),
        ]);

        DB::commit();

        // `headers->set` y no `->header()`: el helper de Illuminate no existe en
        // todas las respuestas que puede devolver una ruta.
        $response->headers->set('Idempotency-Key', $key);

        return $response;
    }

    /**
     * Qué hacer con una clave que ya existe: repetir la respuesta, rechazar por
     * cuerpo distinto, o avisar que hay una petición en vuelo. Null = la reserva
     * está abandonada y el llamador puede tomarla.
     */
    private function replayOrConflict(IdempotencyKey $existing, string $hash): ?Response
    {
        if ($existing->request_hash !== $hash) {
            return $this->fail(__('lab_api.idempotency_reused'), 409, 'idempotency_key_reused');
        }

        if ($existing->response_status !== null) {
            // 200 y no el 201 original: no se creó nada en ESTA petición.
            return response($existing->response_body ?? '', 200)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Key', $existing->key)
                ->header('Idempotent-Replay', 'true');
        }

        if ($existing->created_at && $existing->created_at->lt(now()->subMinutes(self::STALE_MINUTES))) {
            return null;
        }

        return $this->inProgress();
    }

    private function inProgress(): Response
    {
        return $this->fail(__('lab_api.idempotency_in_progress'), 409, 'idempotency_in_progress');
    }

    private function fail(string $message, int $status, string $code): Response
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
