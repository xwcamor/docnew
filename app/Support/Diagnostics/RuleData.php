<?php

namespace App\Support\Diagnostics;

use App\Models\DiagnosticDataset;

/**
 * RuleData — fuente única de los datos de diagnóstico editables (fiquis_rules,
 * cromas_rules, …). Lee de la BD (diagnostic_datasets) si existe la fila;
 * si no (tabla sin migrar o sin sembrar), cae al archivo JSON de origen. Así el
 * motor funciona igual antes y después de mover los datos a BD, y la UI puede
 * editarlos sin tocar archivos.
 */
class RuleData
{
    /** Cache por request: estos datos cambian rara vez. */
    private static array $cache = [];

    public static function get(string $key): array
    {
        // Híbrido por tenant: se prefiere el dataset PROPIO del tenant actual
        // (auth → en diagnóstico/display ese es el tenant del trafo); si no tiene,
        // cae al GLOBAL de fábrica (tenant_id null) y por último al JSON. Con datos
        // actuales (todo global) siempre resuelve al global → idéntico al actual.
        $tenantId = null;
        try {
            $tenantId = auth()->user()?->tenant_id;
        } catch (\Throwable) {
            // sin auth (consola/cola) → global
        }

        $cacheKey = ($tenantId ?? 'g') . ':' . $key;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $data = null;
        try {
            if ($tenantId !== null) {
                $own = DiagnosticDataset::where('key', $key)->where('tenant_id', $tenantId)->first();
                if ($own) {
                    $data = $own->data;
                }
            }
            if (!is_array($data)) {
                $row = DiagnosticDataset::where('key', $key)->whereNull('tenant_id')->first();
                if ($row) {
                    $data = $row->data; // ya casteado a array
                }
            }
        } catch (\Throwable) {
            // Tabla aún no migrada → fallback al archivo.
        }

        if (!is_array($data)) {
            $path = database_path("seeders/data/{$key}.json");
            $data = is_file($path) ? (json_decode(file_get_contents($path), true) ?? []) : [];
        }

        return self::$cache[$cacheKey] = $data;
    }

    /** Invalida el cache (tras editar un set en la UI). Borra todas las variantes por tenant. */
    public static function flush(?string $key = null): void
    {
        if ($key === null) {
            self::$cache = [];
        } else {
            foreach (array_keys(self::$cache) as $ck) {
                if (str_ends_with($ck, ':' . $key)) {
                    unset(self::$cache[$ck]);
                }
            }
        }
    }
}
