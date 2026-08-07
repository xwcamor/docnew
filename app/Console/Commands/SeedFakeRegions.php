<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Genera N regiones falsas para benchmarking via DB::insert (no factory:
 * la factory dispara eventos por fila y se vuelve lentísima a 50k+).
 * Naming "Region 00001"... garantiza unicidad sin tracking en memoria.
 */
class SeedFakeRegions extends Command
{
    protected $signature = 'regions:seed-fake
        {count : Cantidad de regiones a generar}
        {--truncate : Truncar la tabla antes de insertar}';

    protected $description = 'Inserta N regiones falsas en batch para benchmarking de performance';

    public function handle(): int
    {
        $count = (int) $this->argument('count');
        if ($count < 1) {
            $this->error('count debe ser >= 1');
            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            $this->warn('Truncando tabla regions...');
            // delete() en lugar de truncate() porque con FKs activas falla.
            DB::table('regions')->delete();
        }

        $existing = DB::table('regions')->count();
        $this->info("Existentes: {$existing}. Insertando {$count} más en batches de 1000...");

        $batchSize = 1000;
        $batches   = (int) ceil($count / $batchSize);
        $startIdx  = $existing + 1;
        $now       = now();

        $bar = $this->output->createProgressBar($batches);
        $bar->start();

        $totalInserted = 0;
        for ($b = 0; $b < $batches; $b++) {
            $rows = [];
            $thisBatch = min($batchSize, $count - $totalInserted);

            for ($i = 0; $i < $thisBatch; $i++) {
                $idx = $startIdx + $totalInserted + $i;
                $createdDaysAgo = random_int(0, 365);
                $createdAt = $now->copy()->subDays($createdDaysAgo)->subHours(random_int(0, 23));

                $rows[] = [
                    'slug'                => Str::random(22),
                    'name'                => sprintf('Region %05d', $idx),
                    'is_active'           => random_int(1, 100) <= 70,  // 70% activas
                    'created_by'          => null,
                    'deleted_by'          => null,
                    'deleted_description' => null,
                    'created_at'          => $createdAt,
                    'updated_at'          => $createdAt,
                    'deleted_at'          => null,
                ];
            }

            DB::table('regions')->insert($rows);
            $totalInserted += count($rows);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✓ Insertadas {$totalInserted} regiones falsas.");
        $this->info("Total en tabla: " . DB::table('regions')->count());

        return self::SUCCESS;
    }
}
