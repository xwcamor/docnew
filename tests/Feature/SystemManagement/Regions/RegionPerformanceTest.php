<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Models\Region;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RegionPerformanceTest — verifica que las queries del módulo Regions
 * cumplen su budget de tiempo, evitando regresiones por cambios de query
 * o pérdida de índices.
 *
 * **Particularidades:**
 *   - NO usa RefreshDatabase: corre contra la DB real para medir performance
 *     real (no SQLite in-memory). Esto requiere que la DB tenga datos
 *     significativos antes de correr — si tiene < 1000 filas, los tests se
 *     skipean con mensaje claro.
 *   - Marcado con `@group performance` para excluirlo del run default y no
 *     romper CI cuando la DB esté vacía. Se invoca explícitamente:
 *
 *         php artisan regions:seed-fake 50000
 *         php artisan test --group=performance
 *
 * **Budgets:**
 *   Definidos como constantes aquí. Si se vuelven flaky (ruido en CI), se
 *   pueden subir o medir mediana sobre N runs en lugar de un single hit.
 *
 * @group performance
 */
class RegionPerformanceTest extends TestCase
{
    // No usa RefreshDatabase a propósito — corre contra DB real.

    /** Threshold de filas mínimo para que el test tenga sentido. */
    protected const MIN_ROWS = 1000;

    /** Budgets por query (ms). Subir si CI es lento. */
    protected const BUDGET_INDEX_PAGE      = 20;
    protected const BUDGET_COUNT_TOTAL     = 100;
    protected const BUDGET_FILTER_ACTIVE   = 20;
    protected const BUDGET_SORT_BY_NAME    = 20;
    protected const BUDGET_FULL_COMBO      = 30;
    protected const BUDGET_FAVORITES_JOIN  = 50;
    protected const BUDGET_FILTER_NAME_LIKE = 100;

    /** Repeticiones por query — usamos la mediana para estabilidad. */
    protected const RUNS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        // En el environment de tests por default (SQLite in-memory) no tiene
        // sentido medir performance — la base de datos no es la de producción
        // y los planes de ejecución difieren. Skipeamos limpio.
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'Performance tests require Postgres (the production DB engine). ' .
                'Override DB_CONNECTION in phpunit.xml or set it before invoking.'
            );
        }

        $count = Region::count();
        if ($count < self::MIN_ROWS) {
            $this->markTestSkipped(
                "Performance tests need >= " . self::MIN_ROWS . " regions. Current: {$count}. " .
                "Run: php artisan regions:seed-fake 50000"
            );
        }
    }

    public function test_index_page_query_within_budget(): void
    {
        $median = $this->benchmark(fn () => Region::query()
            ->select('id', 'slug', 'name', 'is_active', 'created_at', 'updated_at')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
        );
        $this->assertLessThanOrEqual(
            self::BUDGET_INDEX_PAGE,
            $median,
            "Index page query took {$median}ms, budget is " . self::BUDGET_INDEX_PAGE . 'ms'
        );
    }

    public function test_count_total_within_budget(): void
    {
        $median = $this->benchmark(fn () => Region::query()->count());
        $this->assertLessThanOrEqual(
            self::BUDGET_COUNT_TOTAL,
            $median,
            "COUNT(*) took {$median}ms, budget is " . self::BUDGET_COUNT_TOTAL . 'ms'
        );
    }

    public function test_filter_is_active_within_budget(): void
    {
        $median = $this->benchmark(fn () => Region::query()
            ->where('is_active', true)
            ->limit(10)
            ->get()
        );
        $this->assertLessThanOrEqual(
            self::BUDGET_FILTER_ACTIVE,
            $median,
            "Filter is_active took {$median}ms"
        );
    }

    public function test_sort_by_name_within_budget(): void
    {
        $median = $this->benchmark(fn () => Region::query()
            ->orderBy('name', 'asc')
            ->limit(10)
            ->get()
        );
        $this->assertLessThanOrEqual(
            self::BUDGET_SORT_BY_NAME,
            $median,
            "ORDER BY name took {$median}ms — falta el índice idx_regions_name?"
        );
    }

    public function test_full_combo_within_budget(): void
    {
        $median = $this->benchmark(fn () => Region::query()
            ->select('id', 'slug', 'name', 'is_active', 'created_at')
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
        );
        $this->assertLessThanOrEqual(
            self::BUDGET_FULL_COMBO,
            $median,
            "Filter+sort+paginate took {$median}ms — falta composite index (is_active, created_at)?"
        );
    }

    public function test_favorites_join_within_budget(): void
    {
        // Asume que existe al menos 1 user en la DB (para el LEFT JOIN).
        // Usamos user_id=1 — si no existe simplemente no encuentra favoritos
        // pero la query corre igual y mide el costo del JOIN.
        $median = $this->benchmark(fn () => Region::query()
            ->select('regions.id', 'regions.slug', 'regions.name')
            ->orderByFavoriteFirst(1)
            ->limit(10)
            ->get()
        );
        $this->assertLessThanOrEqual(
            self::BUDGET_FAVORITES_JOIN,
            $median,
            "orderByFavoriteFirst took {$median}ms"
        );
    }

    public function test_filter_name_like_within_budget(): void
    {
        // LIKE substring NO usa el índice btree — este budget es más
        // permisivo. A futuro: pg_trgm + GIN para llevarlo bajo 5ms.
        $median = $this->benchmark(fn () => Region::query()
            ->where('name', 'like', '%01%')
            ->limit(10)
            ->get()
        );
        $this->assertLessThanOrEqual(
            self::BUDGET_FILTER_NAME_LIKE,
            $median,
            "Name LIKE took {$median}ms"
        );
    }

    /** Corre $fn N veces y devuelve la mediana en ms (descarta outliers). */
    protected function benchmark(callable $fn): float
    {
        $times = [];
        for ($i = 0; $i < self::RUNS; $i++) {
            $t0 = microtime(true);
            $fn();
            $times[] = (microtime(true) - $t0) * 1000;
        }
        sort($times);
        return round($times[(int) floor(count($times) / 2)], 2);
    }
}
