<?php

namespace App\Console\Commands;

use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BenchmarkRegions — corrida de benchmarks ad-hoc del módulo Regions.
 *
 * Mide tiempos de las queries reales que ejecuta el listado en producción,
 * con la cantidad de filas que tenga la tabla en este momento. Cada query se
 * corre N veces y se reporta la mediana (más estable que el promedio frente
 * a un primer hit lento por cache cold).
 *
 * Uso:
 *   php artisan regions:benchmark
 *   php artisan regions:benchmark --runs=10        (más muestras, menos ruido)
 *   php artisan regions:benchmark --explain        (muestra EXPLAIN ANALYZE)
 *
 * Salida: tabla con columnas Query / Median / p95 / Status (vs budget).
 */
class BenchmarkRegions extends Command
{
    protected $signature = 'regions:benchmark
        {--runs=5 : Número de repeticiones por query}
        {--explain : Mostrar EXPLAIN ANALYZE de cada query (Postgres only)}';

    protected $description = 'Mide performance de queries del módulo Regions con los datos actuales';

    /**
     * Budgets (presupuesto de tiempo) por query, en ms. Si la mediana supera
     * el budget, marcamos en rojo. Útil como regression check.
     */
    protected array $budgets = [
        'index_page1'        => 5,
        'count_total'        => 50,
        'filter_active'      => 5,
        'filter_date_range'  => 5,
        'filter_name_like'   => 50,
        'sort_by_name'       => 5,
        'offset_deep'        => 50,
        'full_combo'         => 10,
        'with_creator_join'  => 10,
        'favorites_join'     => 15,
    ];

    public function handle(): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $explain = (bool) $this->option('explain');

        $total = DB::table('regions')->count();
        $this->info("Total regiones en DB: {$total}");
        $this->info("Runs por query: {$runs}");
        $this->newLine();

        $cases = $this->cases();
        $rows  = [];

        foreach ($cases as $key => $case) {
            $times = [];
            for ($i = 0; $i < $runs; $i++) {
                $t0 = microtime(true);
                $case['fn']();
                $times[] = (microtime(true) - $t0) * 1000;
            }
            sort($times);
            $median = $times[(int) floor(count($times) / 2)];
            $p95    = $times[(int) floor(count($times) * 0.95)] ?? max($times);
            $budget = $this->budgets[$key] ?? null;
            $status = $budget === null
                ? '—'
                : ($median <= $budget ? '<fg=green>✓ OK</>' : '<fg=red>✗ SLOW</>');

            $rows[] = [
                $case['label'],
                number_format($median, 2) . ' ms',
                number_format($p95, 2) . ' ms',
                $budget !== null ? "≤ {$budget} ms" : '—',
                $status,
            ];

            if ($explain && DB::getDriverName() === 'pgsql' && isset($case['sql'])) {
                $this->newLine();
                $this->line("<fg=cyan>EXPLAIN ANALYZE para: {$case['label']}</>");
                $plan = DB::select('EXPLAIN ANALYZE ' . $case['sql'], $case['bindings'] ?? []);
                foreach ($plan as $line) {
                    $this->line('  ' . $line->{'QUERY PLAN'});
                }
            }
        }

        $this->table(
            ['Query', 'Median', 'p95', 'Budget', 'Status'],
            $rows,
        );

        $slow = collect($rows)->filter(fn ($r) => str_contains($r[4], 'SLOW'))->count();
        $this->newLine();
        if ($slow === 0) {
            $this->info("✓ Todas las queries dentro del budget.");
            return self::SUCCESS;
        }
        $this->warn("✗ {$slow} query/queries sobre el budget.");
        return self::FAILURE;
    }

    /** Cases — la lista de queries a medir. */
    protected function cases(): array
    {
        return [
            'index_page1' => [
                'label' => 'Index page 1 (10 rows, sin filtros)',
                'sql'   => 'SELECT id, slug, name, is_active, created_at FROM regions ORDER BY id DESC LIMIT 10',
                'fn' => fn () => Region::query()
                    ->select('id', 'slug', 'name', 'is_active', 'created_at')
                    ->orderBy('id', 'desc')
                    ->limit(10)
                    ->get(),
            ],
            'count_total' => [
                'label' => 'COUNT(*) total',
                'sql'   => 'SELECT COUNT(*) FROM regions',
                'fn'    => fn () => Region::query()->count(),
            ],
            'filter_active' => [
                'label' => 'Filter is_active = false (10 rows)',
                'sql'   => 'SELECT id, name FROM regions WHERE is_active = false LIMIT 10',
                'fn' => fn () => Region::query()
                    ->where('is_active', false)
                    ->limit(10)
                    ->get(),
            ],
            'filter_date_range' => [
                'label' => 'Filter created_at last 30 days',
                'fn' => fn () => Region::query()
                    ->where('created_at', '>=', now()->subDays(30))
                    ->limit(10)
                    ->get(),
            ],
            'filter_name_like' => [
                'label' => 'Filter name LIKE %0123% (substring)',
                'fn' => fn () => Region::query()
                    ->where('name', 'like', '%0123%')
                    ->limit(10)
                    ->get(),
            ],
            'sort_by_name' => [
                'label' => 'ORDER BY name ASC, limit 10',
                'sql'   => 'SELECT id, name FROM regions ORDER BY name ASC LIMIT 10',
                'fn'    => fn () => Region::query()->orderBy('name', 'asc')->limit(10)->get(),
            ],
            'offset_deep' => [
                'label' => 'OFFSET deep (last page)',
                'fn' => function () {
                    $total = Region::count();
                    $offset = max(0, $total - 10);
                    Region::query()->orderBy('id', 'asc')->offset($offset)->limit(10)->get();
                },
            ],
            'full_combo' => [
                'label' => 'FULL: filter is_active + sort created_at desc + page 1',
                'fn' => fn () => Region::query()
                    ->select('id', 'slug', 'name', 'is_active', 'created_at')
                    ->where('is_active', true)
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get(),
            ],
            'with_creator_join' => [
                'label' => 'Index con eager-load creator (lo que usa el controller)',
                'fn' => fn () => Region::query()
                    ->select('regions.id', 'regions.slug', 'regions.name', 'regions.is_active', 'regions.created_at', 'regions.created_by')
                    ->with(['creator:id,name,email'])
                    ->orderBy('regions.id', 'desc')
                    ->limit(10)
                    ->get(),
            ],
            'favorites_join' => [
                'label' => 'Con orderByFavoriteFirst (LEFT JOIN user_favorites)',
                'fn' => fn () => Region::query()
                    ->select('regions.id', 'regions.slug', 'regions.name', 'regions.is_active')
                    ->orderByFavoriteFirst(1)  // assume user 1 exists
                    ->limit(10)
                    ->get(),
            ],
        ];
    }
}
