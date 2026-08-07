<?php

namespace Tests\Unit;

use App\Models\Chromatographical;
use App\Services\Diagnostics\RatioMethodsService;
use Tests\TestCase;

class RatioMethodsServiceTest extends TestCase
{
    private RatioMethodsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new RatioMethodsService();
    }

    private function sample(array $gases): Chromatographical
    {
        return (new Chromatographical())->forceFill($gases);
    }

    public function test_rogers_thermal_over_700(): void
    {
        // R1=C2H2/C2H4=0.01 (<0.1), R2=CH4/H2=10 (>1), R3=C2H4/C2H6=10 (>3) → t2.
        $r = $this->svc->evaluate($this->sample([
            'h2' => 10, 'ch4' => 100, 'c2h4' => 100, 'c2h6' => 10, 'c2h2' => 1,
        ]));
        $this->assertSame('t2', $r['rogers']['fault']);
        $this->assertTrue($r['rogers']['complete']);
    }

    public function test_rogers_normal_unit(): void
    {
        // R1=0.05 (<0.1), R2=0.5 (0.1–1), R3=0.5 (<1) → normal.
        $r = $this->svc->evaluate($this->sample([
            'h2' => 100, 'ch4' => 50, 'c2h4' => 10, 'c2h6' => 20, 'c2h2' => 0.5,
        ]));
        $this->assertSame('normal', $r['rogers']['fault']);
    }

    public function test_doernenburg_thermal(): void
    {
        // R1=CH4/H2=10 (>1), R2=C2H2/C2H4=0.1 (<0.75), R3=C2H2/CH4=0.1 (<0.3),
        // R4=C2H6/C2H2=5 (>0.4) → descomposición térmica.
        $r = $this->svc->evaluate($this->sample([
            'h2' => 10, 'ch4' => 100, 'c2h4' => 100, 'c2h6' => 50, 'c2h2' => 10,
        ]));
        $this->assertSame('thermal', $r['doernenburg']['fault']);
    }

    public function test_incomplete_when_division_by_zero(): void
    {
        // c2h4 = 0 → R1 de Rogers no calculable → método no aplica.
        $r = $this->svc->evaluate($this->sample([
            'h2' => 10, 'ch4' => 100, 'c2h4' => 0, 'c2h6' => 10, 'c2h2' => 1,
        ]));
        $this->assertFalse($r['rogers']['complete']);
        $this->assertNull($r['rogers']['fault']);
    }

    public function test_no_match_returns_null_fault(): void
    {
        // Valores que no caen en ningún caso de Rogers → fault null pero completo.
        $r = $this->svc->evaluate($this->sample([
            'h2' => 100, 'ch4' => 5, 'c2h4' => 100, 'c2h6' => 90, 'c2h2' => 50,
        ]));
        $this->assertTrue($r['rogers']['complete']);
        $this->assertNull($r['rogers']['fault']);
    }
}
