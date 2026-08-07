<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;

class CustomerEditAllPaginationTest extends CustomerTestCase
{
    /**
     * Regresion: el navegador manda is_active= (vacio) al paginar el Edit-All.
     * El guard buggy lo trataba como filtro is_active=false -> 0 resultados.
     */
    public function test_edit_all_page_two_with_empty_is_active_returns_rows(): void
    {
        $this->actingAsTenantAdmin(1);

        for ($i = 1; $i <= 25; $i++) {
            Customer::factory()->create([
                'tenant_id' => 1, 'country_id' => 1,
                'name' => sprintf('Cliente %02d', $i), 'is_active' => true,
            ]);
        }

        $base = route('business_management.customers.edit_all');

        $p1 = $this->get($base . '?name=&is_active=&sort=id&direction=asc&per_page=10&page=1');
        $p1->assertOk();
        $ids1 = collect($p1->viewData('page')['props']['customers']['data'])->pluck('id')->all();

        $p2 = $this->get($base . '?name=&is_active=&sort=id&direction=asc&per_page=10&page=2');
        $p2->assertOk();
        $props2 = $p2->viewData('page')['props']['customers'];
        $ids2 = collect($props2['data'])->pluck('id')->all();

        $this->assertSame(25, $props2['total'], 'is_active= vacio no debe filtrar');
        $this->assertCount(10, $ids2, 'la pagina 2 debe traer 10 filas');
        $this->assertEmpty(array_intersect($ids1, $ids2), 'la pagina 2 no debe repetir la pagina 1');
    }
}
