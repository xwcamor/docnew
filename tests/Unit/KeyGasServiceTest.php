<?php

namespace Tests\Unit;

use App\Models\Chromatographical;
use App\Services\Diagnostics\KeyGasService;
use Tests\TestCase;

class KeyGasServiceTest extends TestCase
{
    private KeyGasService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new KeyGasService();
    }

    private function sample(array $g): Chromatographical
    {
        $c = new Chromatographical();
        foreach (['co', 'h2', 'ch4', 'c2h6', 'c2h4', 'c2h2'] as $k) {
            $c->{$k} = $g[$k] ?? 0;
        }
        return $c;
    }

    public function test_high_acetylene_is_arcing(): void
    {
        $r = $this->svc->evaluate($this->sample(['h2' => 600, 'ch4' => 50, 'c2h6' => 16, 'c2h4' => 33, 'c2h2' => 300, 'co' => 1]));
        $this->assertSame('arco', $r['fault']);
        $this->assertSame('c2h2', $r['key_gas']);
    }

    public function test_dominant_hydrogen_is_corona(): void
    {
        $r = $this->svc->evaluate($this->sample(['h2' => 860, 'ch4' => 130, 'c2h6' => 5, 'c2h4' => 2, 'c2h2' => 1, 'co' => 2]));
        $this->assertSame('corona', $r['fault']);
    }

    public function test_high_ethylene_is_overheated_oil(): void
    {
        $r = $this->svc->evaluate($this->sample(['h2' => 20, 'ch4' => 160, 'c2h6' => 170, 'c2h4' => 630, 'c2h2' => 20, 'co' => 1]));
        $this->assertSame('sobre_aceite', $r['fault']);
    }

    public function test_high_co_is_overheated_cellulose(): void
    {
        $r = $this->svc->evaluate($this->sample(['co' => 920, 'h2' => 67, 'ch4' => 12, 'c2h6' => 1, 'c2h4' => 1, 'c2h2' => 1]));
        $this->assertSame('sobre_celulosa', $r['fault']);
        $this->assertSame('co', $r['key_gas']);
    }

    public function test_measured_profile_sums_to_100_and_confidence_high_on_match(): void
    {
        $r = $this->svc->evaluate($this->sample(['h2' => 860, 'ch4' => 130, 'c2h6' => 5, 'c2h4' => 2, 'c2h2' => 1, 'co' => 2]));
        $this->assertEqualsWithDelta(100, array_sum($r['measured']), 0.5);
        $this->assertGreaterThan(80, $r['confidence']); // perfil casi idéntico a la firma
    }

    public function test_no_gases_returns_null(): void
    {
        $this->assertNull($this->svc->evaluate($this->sample([])));
    }
}
