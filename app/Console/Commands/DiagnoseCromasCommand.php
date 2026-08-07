<?php

namespace App\Console\Commands;

use App\Models\Chromatographical;
use App\Services\Diagnostics\ChromatographyEngine;
use Illuminate\Console\Command;

/**
 * Comando de prueba: diagnostica una muestra de cromatografía por su ID.
 *
 *   php artisan diagnose:cromas {id}
 *
 * Muestra el score (DGAF), la condición (semáforo) y el detalle por gas.
 * Sirve para verificar que el motor da el mismo resultado que el sistema viejo.
 */
class DiagnoseCromasCommand extends Command
{
    protected $signature = 'diagnose:cromas {id : ID de la muestra chromatographical}';
    protected $description = 'Diagnostica una muestra de cromatografía y muestra el resultado';

    public function handle(ChromatographyEngine $engine): int
    {
        $sample = Chromatographical::with('transformer.oilType', 'transformer.transformerType')->find($this->argument('id'));

        if (!$sample) {
            $this->error("No existe la muestra con ID {$this->argument('id')}.");
            return self::FAILURE;
        }

        $t = $sample->transformer;
        $this->info("Transformador: {$t->tag} (serie {$t->serial})");
        $this->line("  Aceite: " . ($t->oilType?->name ?? '—') . " · Tipo: " . ($t->transformerType?->name ?? '—'));
        $this->line("  Muestra del: {$sample->sample_date->format('Y-m-d')}");
        $this->newLine();

        $result = $engine->evaluate($sample);

        $this->line("  Gases medidos:");
        foreach ($result->detail as $gas => $d) {
            $this->line(sprintf("    %-5s = %8.2f  ->  score %d (peso %d)",
                strtoupper($gas), $d['value'], $d['score'], $d['weight']));
        }
        if (!empty($result->missing)) {
            $this->line("  Gases faltantes (no penalizan): " . implode(', ', array_map('strtoupper', $result->missing)));
        }

        $this->newLine();
        $this->info(sprintf("  DGAF (score): %.4f", $result->score));
        $this->info("  Diagnóstico: {$result->condition}  [{$result->color}]");
        $this->line("  Rating para Índice de Salud: {$result->hiRating}");

        return self::SUCCESS;
    }
}
