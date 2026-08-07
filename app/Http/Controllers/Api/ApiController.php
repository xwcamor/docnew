<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Base class for all API controllers. Provides consistent JSON response shapes
 * so external integrations can rely on a stable envelope across endpoints.
 *
 * Conventions:
 *   - Success:     { "data": ... } (Resources handle this automatically)
 *   - Error:       { "message": "...", "errors": {...}, "code": "..." }
 *   - List:        Resource::collection() returns { data, links, meta }
 */
abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    protected function created(mixed $data = null): JsonResponse
    {
        return response()->json(['data' => $data], 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function error(string $message, int $status = 400, ?string $code = null, array $details = []): JsonResponse
    {
        $payload = ['message' => $message];
        if ($code) $payload['code'] = $code;
        if (!empty($details)) $payload['errors'] = $details;
        return response()->json($payload, $status);
    }
}
