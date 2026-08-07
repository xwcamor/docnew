<?php

use App\Http\Controllers\AuthManagement\AuthController;
use App\Http\Controllers\Api\V1\CustomerApiController;
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
