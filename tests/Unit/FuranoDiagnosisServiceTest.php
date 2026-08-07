<?php

namespace Tests\Unit;

use App\Models\ResultScale;
use App\Models\Test;
use App\Services\Diagnostics\FuranoDiagnosisService;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FuranoDiagnosisServiceTest extends TestCase
{
    use RefreshDatabase;

    private FuranoDiagnosisService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiagnosticCatalogSeeder::class);
        $this->svc = new FuranoDiagnosisService();
    }

    public function test_null_fal_returns_empty(): void
    {
        $r = $this->svc->evaluate(null);
        $this->assertNull($r['condition']);
        $this->assertNull($r['dp']);
        $this->assertNull($r['rating']);
    }

    public function test_low_fal_is_muy_bueno_with_high_dp(): void
    {
        // 50 ppb = 0.05 ppm < 0.1 -> Muy Bueno (rating 4). DP alto (papel nuevo).
        $r = $this->svc->evaluate(50);
        $this->assertSame('Muy Bueno', $r['condition']);
        $this->assertSame(4.0, $r['rating']);
        // Chendong: (1.51 - log10(0.05))/0.0035 ≈ 803
        $this->assertEqualsWithDelta(803, $r['dp'], 2);
    }

    public function test_high_fal_is_muy_malo_with_low_dp(): void
    {
        // 7000 ppb = 7 ppm > 6 -> Muy Malo (rating 0). DP ≤ 200 = fin de vida.
        $r = $this->svc->evaluate(7000);
        $this->assertSame('Muy Malo', $r['condition']);
        $this->assertSame(0.0, $r['rating']);
        // Chendong: (1.51 - log10(7))/0.0035 ≈ 190
        $this->assertEqualsWithDelta(190, $r['dp'], 2);
    }

    public function test_mid_fal_lands_in_middle_scale(): void
    {
        // Semáforo conservador del viejo: 1000 ppb = 1.0 ppm -> [1.0, ∞) ->
        // Muy Malo (rating 0). El DP informativo sigue siendo Chendong ≈ 431.
        $r = $this->svc->evaluate(1000);
        $this->assertSame('Muy Malo', $r['condition']);
        $this->assertSame(0.0, $r['rating']);
        $this->assertEqualsWithDelta(431, $r['dp'], 2);

        // 300 ppb = 0.3 ppm -> [0.25, 0.5) -> Medio (rating 2).
        $r2 = $this->svc->evaluate(300);
        $this->assertSame('Medio', $r2['condition']);
        $this->assertSame(2.0, $r2['rating']);
    }

    public function test_paper_life_percent_from_dp(): void
    {
        // DP 1000 nuevo → 0% · DP 200 fin de vida → 100% · DP 600 → 50%. Acotado.
        $this->assertSame(0, $this->svc->paperLifePercent(1000));
        $this->assertSame(100, $this->svc->paperLifePercent(200));
        $this->assertSame(50, $this->svc->paperLifePercent(600));
        $this->assertSame(100, $this->svc->paperLifePercent(150)); // clamp
        $this->assertNull($this->svc->paperLifePercent(null));
    }

    public function test_evaluate_includes_life_percent(): void
    {
        // 1000 ppb = 1 ppm → DP ≈ 431 → vida consumida (1000-431)/800 ≈ 71%.
        $r = $this->svc->evaluate(1000);
        $this->assertEqualsWithDelta(71, $r['life_percent'], 1);
    }

    public function test_upgraded_paper_flags_warning_in_explain(): void
    {
        // El papel térmicamente mejorado subestima la degradación -> aviso.
        $up = $this->svc->explain(1000, 'upgraded');
        $this->assertTrue($up['paper_warning']);

        $kraft = $this->svc->explain(1000, 'kraft');
        $this->assertFalse($kraft['paper_warning']);

        $unknown = $this->svc->explain(1000, null);
        $this->assertFalse($unknown['paper_warning']);
    }

    public function test_zero_fal_has_no_dp_but_best_condition(): void
    {
        $r = $this->svc->evaluate(0);
        $this->assertSame('Muy Bueno', $r['condition']);
        $this->assertNull($r['dp']); // log10(0) indefinido
    }
}
