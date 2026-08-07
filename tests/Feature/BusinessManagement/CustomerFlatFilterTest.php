<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Filtro avanzado PLANO con conector por cláusula (estilo RENATI) aplicado vía el
 * índice (round-trip HTTP): controller → scopeFilter → FilterApplier. Verifica
 * que "name = Alpha  OR  cod = 2002" filtre bien y que las cláusulas (con su
 * 'conj') se persistan de vuelta en los props (saved views).
 */
class CustomerFlatFilterTest extends CustomerTestCase
{
    private function mk(string $name, string $cod): Customer
    {
        $c = new Customer();
        $c->forceFill([
            'slug' => 'cu-' . uniqid(), 'name' => $name, 'cod' => $cod,
            'country_id' => 1, 'is_active' => true, 'tenant_id' => 1,
        ])->save();
        return $c;
    }

    public function test_flat_or_filters_correctly(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->mk('Alpha Corp', '1001');   // matchea por nombre Alpha
        $this->mk('Beta Ltd',   '2002');   // matchea por (cod 2002)
        $this->mk('Gamma SA',   '3003');   // NO matchea

        // name = "Alpha Corp"  OR  cod = "2002"
        // Se usa '=' y no 'contains' porque ese último emite ILIKE (Postgres en
        // prod), que el SQLite de los tests no soporta. Lo importante acá es el
        // conector 'or' por cláusula.
        $clauses = [
            ['field' => 'name', 'op' => '=', 'value' => 'Alpha Corp'],
            ['field' => 'cod',  'op' => '=', 'value' => '2002', 'conj' => 'or'],
        ];

        $res = $this->get(route('business_management.customers.index', [
            'advanced_where' => json_encode($clauses),
        ]));

        $res->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Customers/Index')
            // Solo Alpha y Beta (2), no Gamma.
            ->where('customers.data', fn ($rows) => collect($rows)->count() === 2
                && collect($rows)->pluck('name')->sort()->values()->all() === ['Alpha Corp', 'Beta Ltd'])
            // Las cláusulas (con su conj) se persisten de vuelta para saved views.
            ->where('filters.advanced_where', fn ($w) => collect($w)->contains(fn ($r) => ($r['conj'] ?? null) === 'or'))
        );
    }
}
