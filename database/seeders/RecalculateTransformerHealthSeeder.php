<?php

namespace Database\Seeders;

use App\Models\Transformer;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Database\Seeder;

/**
 * RecalculateTransformerHealthSeeder — recalcula y PERSISTE el Índice de Salud
 * de cada transformador con el motor NUEVO, desde las muestras importadas.
 *
 * Por qué: el LegacyTransformersSeeder trae `health_index`/`health_state` como
 * snapshot cacheado del sistema VIEJO (num_health/state_health). El listado de
 * trafos ordena/filtra por ese campo persistido, mientras que el detalle
 * recalcula en vivo con el motor nuevo → desacuerdan. Esto corre el motor nuevo
 * (HealthIndexService::evaluate con persist) sobre las muestras reales y
 * sobrescribe el snapshot viejo, dejando el health del trafo consistente con su
 * diagnóstico real.
 *
 * Debe correr DESPUÉS de importar las muestras y de deduplicar (así usa la
 * última muestra correcta de cada prueba).
 */
class RecalculateTransformerHealthSeeder extends Seeder
{
    public function run(): void
    {
        $svc = app(HealthIndexService::class);
        $count = 0;

        // Solo los trafos visibles (no soft-deleted): son los que el listado
        // muestra y ordena por health_index. Los de papelera se recalcularán
        // al restaurarse. (withoutGlobalScopes incluiría borrados cuyo
        // $sample->transformer resuelve a null y rompería el motor.)
        Transformer::query()
            ->with('oilType', 'transformerType')
            ->chunkById(200, function ($transformers) use ($svc, &$count) {
                foreach ($transformers as $t) {
                    // notify:false — backfill masivo: sin alertas (inundaría la campana).
                    $svc->evaluate($t, persist: true, notify: false);
                    $count++;
                }
            });

        $this->command?->info("RecalculateTransformerHealthSeeder: HI recalculado y persistido para {$count} transformadores.");
    }
}
