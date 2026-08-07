<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use App\Models\CustomerLocation;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Orden por conteos de jerarquía (locations_count, etc.): alias del SELECT
 * (withCount/subquery), no columnas de la tabla. Verifica orden del set completo.
 */
class CustomerCountSortTest extends CustomerTestCase
{
    private function mk(string $name, int $locations): Customer
    {
        $c = new Customer();
        $c->forceFill([
            'slug' => 'cu-' . uniqid(), 'name' => $name, 'cod' => $name,
            'country_id' => 1, 'is_active' => true, 'tenant_id' => 1,
        ])->save();
        for ($i = 0; $i < $locations; $i++) {
            $l = new CustomerLocation();
            $l->forceFill([
                'slug' => 'lo-' . uniqid(), 'name' => "L{$i}", 'customer_id' => $c->id,
                'tenant_id' => 1,
            ])->save();
        }
        return $c;
    }

    public function test_sorts_by_locations_count_desc(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->mk('Few', 1);
        $this->mk('Many', 3);
        $this->mk('None', 0);

        $res = $this->get(route('business_management.customers.index', [
            'sort' => 'locations_count', 'direction' => 'desc',
        ]));

        $res->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('customers.data', fn ($rows) =>
                collect($rows)->pluck('name')->values()->all() === ['Many', 'Few', 'None'])
        );
    }
}
