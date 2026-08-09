<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Models\Region;

/**
 * Las pantallas de Regiones abren. Es la prueba barata que encuentra los fallos
 * caros: una ficha que lee una columna inexistente o un formulario al que le
 * falta un catalogo revientan aqui y no en produccion.
 */
class RegionScreensTest extends RegionTestCase
{
    public function test_todas_las_pantallas_abren(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        foreach (['index', 'create', 'trash', 'edit_all'] as $pantalla) {
            $this->get(route("system_management.regions.{$pantalla}"))->assertOk();
        }

        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("system_management.regions.{$pantalla}", $region->slug))->assertOk();
        }
    }
}
