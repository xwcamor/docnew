<?php

namespace Tests\Unit;

use App\Services\Diagnostics\FpotDiagnosisService;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Escala de Factor de Potencia (factor.rb): <0.5 Muy Bueno (4) · 0.5-0.7 Bueno (3)
 * · 0.7-1.0 Medio (2) · 1.0-2.0 Malo (1) · ≥2.0 Muy Malo (0). Umbrales en datos.
 */
class FpotDiagnosisServiceTest extends TestCase
{
    use RefreshDatabase;

    private FpotDiagnosisService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DiagnosticCatalogSeeder::class);
        $this->svc = new FpotDiagnosisService();
    }

    public function test_null_value_returns_empty(): void
    {
        $r = $this->svc->evaluate(null);
        $this->assertNull($r['condition']);
        $this->assertNull($r['rating']);
    }

    public function test_low_value_is_muy_bueno(): void
    {
        $r = $this->svc->evaluate(0.3);
        $this->assertSame('Muy Bueno', $r['condition']);
        $this->assertSame('green', $r['color']);
        $this->assertSame(4.0, $r['rating']);
    }

    public function test_boundary_05_is_bueno(): void
    {
        // contains() es [from, to): 0.5 cae en [0.5, 0.7) → Bueno (3).
        $r = $this->svc->evaluate(0.5);
        $this->assertSame('Bueno', $r['condition']);
        $this->assertSame(3.0, $r['rating']);
    }

    public function test_mid_value_is_medio(): void
    {
        $r = $this->svc->evaluate(0.85);
        $this->assertSame('Medio', $r['condition']);
        $this->assertSame('yellow', $r['color']);
        $this->assertSame(2.0, $r['rating']);
    }

    public function test_high_value_is_malo(): void
    {
        $r = $this->svc->evaluate(1.5);
        $this->assertSame('Malo', $r['condition']);
        $this->assertSame('orange', $r['color']);
        $this->assertSame(1.0, $r['rating']);
    }

    public function test_very_high_value_is_muy_malo(): void
    {
        $r = $this->svc->evaluate(2.5);
        $this->assertSame('Muy Malo', $r['condition']);
        $this->assertSame(0.0, $r['rating']);
    }
}
