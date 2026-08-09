<?php

namespace Tests;

use App\Models\Plan;
use App\Scopes\HideSuperScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\DetectaColumnasInventadas;

abstract class TestCase extends BaseTestCase
{
    use DetectaColumnasInventadas;

    protected function setUp(): void
    {
        parent::setUp();

        // Una columna que no existe pasa desapercibida en SQLite —lee las
        // comillas dobles como texto— y revienta en PostgreSQL. Asi se colo el
        // panel roto con las 1212 pruebas en verde.
        $this->vigilarColumnasInventadas();

        // La cache estatica de Plan::findBySlug() persiste entre tests dentro
        // del mismo proceso PHP. RefreshDatabase no la resetea — la limpiamos
        // aca para que cada test arranque con cache limpia.
        Plan::flushCache();

        // Mismo problema: HideSuperScope cachea los role ids (super/api) de forma
        // estatica. Con RefreshDatabase los roles se recrean con IDs nuevos por
        // test; sin resetear, el scope filtraria por el id viejo (rol equivocado).
        HideSuperScope::flushCache();
    }

    protected function tearDown(): void
    {
        // Se MIRA antes de desmontar —despues ya no hay conexion que preguntar—
        // y se FALLA despues, o el tearDown se corta a la mitad y la
        // transaccion de RefreshDatabase se queda abierta: la prueba siguiente
        // muere con «cannot start a transaction within a transaction».
        //
        // Y no dentro del oyente: fallar en medio de la peticion la convierte
        // en un 500 y lo que se lee es «Not a valid Inertia response».
        $inventadas = $this->columnasInventadasDetectadas();

        parent::tearDown();

        $this->denunciarColumnasInventadas($inventadas);
    }
}
