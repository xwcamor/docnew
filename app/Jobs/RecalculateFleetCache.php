<?php

namespace App\Jobs;

use App\Models\Transformer;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalcula la caché de flota (health, fault_type, ieee_condition, paper_dp…)
 * tras EDITAR REGLAS en el editor de diagnóstico. Sin esto, los trafos
 * existentes mostrarían en dashboard/listados los valores cacheados con las
 * reglas viejas hasta recibir su siguiente muestra (que puede tardar meses).
 * La ficha individual no lo necesita (calcula en vivo).
 *
 * Scope: $tenantId null = flota completa (super editó el estándar GLOBAL);
 * con id = solo los trafos de ese workspace (un admin personalizó SUS reglas).
 *
 * Debounce: ShouldBeUniqueUntilProcessing + delay al despachar → varios
 * "Guardar" seguidos en una sesión de edición colapsan en UN solo recálculo
 * (mientras el job espera en cola, los dispatch repetidos se descartan; si un
 * guardado llega cuando YA está procesando, se encola uno nuevo — nunca queda
 * un cambio sin recalcular).
 *
 * Equivalente en cola del comando `php artisan diagnose:fleet-cache` (que
 * queda como respaldo manual / post-deploy).
 */
class RecalculateFleetCache implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // flota completa: ~2 min por cada mil trafos

    public function __construct(public ?int $tenantId = null)
    {
    }

    public function uniqueId(): string
    {
        return 'fleet-recalc:' . ($this->tenantId ?? 'global');
    }

    public function handle(HealthIndexService $hi): void
    {
        Transformer::withoutGlobalScopes()
            ->when($this->tenantId !== null, fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->with(['oilType', 'transformerType'])
            ->chunkById(200, function ($chunk) use ($hi) {
                foreach ($chunk as $t) {
                    // Sin notificar: es un recálculo masivo por cambio de
                    // reglas, no un cambio de estado real del trafo.
                    $hi->evaluate($t, persist: true, notify: false);
                }
            });
    }
}
