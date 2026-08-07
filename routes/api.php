<?php

use App\Http\Controllers\AuthManagement\AuthController;
use App\Http\Controllers\Api\V1\CustomerApiController;
use App\Http\Controllers\Api\V1\LabResultApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — versioned under /api/v1/
|--------------------------------------------------------------------------
|
| Token-authenticated business API. Each token belongs to a workspace's
| system user (api+{slug}@system.local), so BelongsToTenant auto-filters
| every query to that tenant's data.
|
| Token abilities (Sanctum native) act as fine-grained permissions:
|   customers:read, customers:write, customers:delete  (ejemplo)
|
| Modules CORE (tenants, system_modules, settings, languages, countries,
| locales, regions) NO se exponen aca: son super only desde la UI web.
| Si en el futuro un integrador necesita master data de read-only, lo
| pensamos puntual — por ahora Customers es el unico ejemplo del patron.
|
| Rate limiting: 'api' throttle group = 60 req/min (config/auth).
*/

// ─── Auth ────────────────────────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/me', [AuthController::class, 'me']);

// ─── V1 — token-authenticated business API ──────────────────────────────────
// plan_feature:api_access → bloquea si el tenant no tiene API en su plan
// (solo enterprise hoy). El token + tenant scoping siguen funcionando para
// super pero un tenant con plan free/basic/pro recibe 402.
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api', 'plan_feature:api_access'])
    ->group(function () {

        // ── Customers ──────────────────────────────────────────────────────
        // Template del patron de API REST. Cuando armemos un modulo de negocio
        // nuevo con API expuesta, clonar este grupo cambiando customers→{módulo}.
        //
        // Token abilities:
        //   GET    → customers:read
        //   POST   → customers:write
        //   PUT    → customers:write
        //   DELETE → customers:delete
        Route::middleware('ability:customers:read')->group(function () {
            Route::get('customers',         [CustomerApiController::class, 'index']);
            Route::get('customers/{slug}',  [CustomerApiController::class, 'show']);
        });
        Route::middleware('ability:customers:write')->group(function () {
            Route::post('customers',         [CustomerApiController::class, 'store']);
            Route::put('customers/{slug}',   [CustomerApiController::class, 'update']);
            Route::patch('customers/{slug}', [CustomerApiController::class, 'update']);
            // Bulk: paridad con la web. Para batches > threshold, devuelve 202
            // y dispatcha a queue (igual que UI).
            Route::post('customers/bulk-set-active', [CustomerApiController::class, 'bulkSetActive']);
        });
        Route::middleware('ability:customers:delete')->group(function () {
            Route::delete('customers/{slug}',     [CustomerApiController::class, 'destroy']);
            Route::post('customers/bulk-delete',  [CustomerApiController::class, 'bulkDelete']);
        });

        // ── Laboratorio ────────────────────────────────────────────────────
        // Entrada de resultados de ensayo del sistema de laboratorio (TR LAB).
        // Reemplaza la escritura directa contra nuestra base. Contrato completo,
        // ejemplos y errores: docs/API-LABORATORIO.md
        //
        // Token abilities:
        //   transformers:read  → buscar el equipo al que pertenece una muestra
        //   transformers:write → darlo de alta cuando no existe
        //   lab:write          → cargar resultados
        //
        // NO se agregó un plan_feature propio: `api_access` (enterprise) ya
        // gatea todo /api/v1 y una feature nueva habría que darla de alta en
        // cada fila de la tabla `plans` —que es la fuente de verdad— o el
        // workspace recibiría 402 aun teniendo el plan que corresponde.
        Route::middleware('ability:transformers:read')->group(function () {
            Route::get('transformers/lookup', [LabResultApiController::class, 'lookup']);
        });
        Route::middleware(['ability:transformers:write', 'idempotency:optional'])->group(function () {
            Route::post('transformers', [LabResultApiController::class, 'storeTransformer']);
        });
        // La clave de idempotencia es OBLIGATORIA acá: es el único punto donde
        // un reintento tras un timeout duplicaba muestras (el índice
        // (transformer_id, sample_date) no es único).
        Route::middleware(['ability:lab:write', 'idempotency'])->group(function () {
            Route::post('lab-results', [LabResultApiController::class, 'store']);
        });
    });
