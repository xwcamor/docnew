<?php

namespace Tests;

use App\Models\Plan;
use App\Scopes\HideSuperScope;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La cache estatica de Plan::findBySlug() persiste entre tests dentro
        // del mismo proceso PHP. RefreshDatabase no la resetea — la limpiamos
        // aca para que cada test arranque con cache limpia.
        Plan::flushCache();

        // Mismo problema: HideSuperScope cachea los role ids (super/api) de forma
        // estatica. Con RefreshDatabase los roles se recrean con IDs nuevos por
        // test; sin resetear, el scope filtraria por el id viejo (rol equivocado).
        HideSuperScope::flushCache();
    }
}
