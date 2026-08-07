<?php

namespace App\Console\Commands;

use App\Models\Transformer;
use App\Services\Diagnostics\HealthIndexService;
use Illuminate\Console\Command;

/**
 * Comando de prueba: calcula el Índice de Salud (HI) de un transformador.
 *
 *   php artisan diagnose:health {id}
 *
 * Muestra el HI (0-100), el semáforo, y qué pruebas entraron al cálculo. Sirve
 * para verificar el peso dinámico: con solo cromatografía perfecta debe dar 100.
 */
class DiagnoseHealthCommand extends Command
{
    protected $signature = 'diagnose:health {id : ID del transformador}';
    protected $description = 'Calcula el Índice de Salud de un transformador y muestra el detalle';

    public function handle(HealthIndexService $service): int
    {
        $transformer = Transformer::with('oilType', 'transformerType')->find($this->argument('id'));

        if (!$transformer) {
            $this->error("No existe el transformador con ID {$this->argument('id')}.");
            return self::FAILURE;
        }

        $this->info("Transformador: {$transformer->tag} (serie {$transformer->serial})");
        $this->line("  Aceite: " . ($transformer->oilType?->name ?? '—') . " · Tipo: " . ($transformer->transformerType?->name ?? '—'));
        $this->newLine();

        $result = $service->evaluate($transformer);

        $this->line("  Pruebas:");
        foreach ($result->components as $code => $c) {
            $estado = $c['present']
                ? sprintf("rating %s (peso %d)", $c['rating'], $c['weight'])
                : "sin datos (no penaliza)";
            $this->line(sprintf("    %-8s %-22s -> %s", strtoupper($code), $c['name'], $estado));
        }

        $this->newLine();
        if (!$result->hasData()) {
            $this->warn("  Sin datos: no hay ninguna prueba con resultados para calcular el HI.");
            return self::SUCCESS;
        }

        $this->info(sprintf("  Índice de Salud: %.2f", $result->index));
        $this->info("  Estado: {$result->condition}  [{$result->color}]");
        if ($result->isPartial()) {
            $this->line("  Diagnóstico parcial — faltan: " . implode(', ', array_map('strtoupper', $result->missing)));
        }

        return self::SUCCESS;
    }
}
