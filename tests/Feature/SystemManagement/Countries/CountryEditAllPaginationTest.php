<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\Country;

class CountryEditAllPaginationTest extends CountryTestCase
{
    /** Regresion: is_active= vacio al paginar el Edit-All no debe vaciar la query. */
    public function test_edit_all_page_two_with_empty_is_active_returns_rows(): void
    {
        $this->actingAsSuperAdmin();

        for ($i = 1; $i <= 25; $i++) {
            Country::factory()->create(['name' => sprintf('Pais %02d', $i), 'is_active' => true]);
        }

        $base = route('system_management.countries.edit_all');
        $p2 = $this->get($base . '?name=&is_active=&sort=id&direction=asc&per_page=10&page=2');
        $p2->assertOk();
        $props = $p2->viewData('page')['props']['countries'];

        $this->assertGreaterThan(10, $props['total'], 'is_active= vacio no debe filtrar todo');
        $this->assertNotEmpty($props['data'], 'la pagina 2 no debe venir vacia');
    }
}
