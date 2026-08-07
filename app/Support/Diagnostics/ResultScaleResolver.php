<?php

namespace App\Support\Diagnostics;

use App\Models\ResultScale;
use Illuminate\Support\Collection;

/**
 * ResultScaleResolver — resuelve las bandas del semáforo de una prueba en modo
 * HÍBRIDO por tenant: prefiere las del tenant ACTUAL (auth → en diagnóstico/
 * display ese es el tenant del trafo) y, si no tiene propias, cae a las GLOBALES
 * de fábrica (tenant_id null). Mismo patrón que RuleData.
 *
 * $testId null = escala global del Índice de Salud (result_scales.test_id null).
 */
class ResultScaleResolver
{
    /** @return Collection<ResultScale> bandas ordenadas por sort_order. */
    public static function bands(?int $testId): Collection
    {
        $tenantId = null;
        try {
            $tenantId = auth()->user()?->tenant_id;
        } catch (\Throwable) {
            // sin auth (consola/cola) → global
        }

        if ($tenantId !== null) {
            $own = self::base($testId)->where('tenant_id', $tenantId)->orderBy('sort_order')->get();
            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        return self::base($testId)->whereNull('tenant_id')->orderBy('sort_order')->get();
    }

    private static function base(?int $testId)
    {
        $q = ResultScale::query();

        return $testId === null ? $q->whereNull('test_id') : $q->where('test_id', $testId);
    }
}
