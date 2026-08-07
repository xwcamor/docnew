<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Orden por PAÍS (columna sin dataIndex → sortea por su key vía left join a
 * countries.name en el backend). Verifica que ordene el SET COMPLETO (no solo la
 * página visible) y que siga funcionando con un filtro avanzado aplicado.
 *
 * countries: id=1 Argentina, id=2 Peru (seedParentRows).
 */
class CustomerCountrySortTest extends CustomerTestCase
{
    private function mk(string $name, int $countryId, bool $active = true): Customer
    {
        $c = new Customer();
        $c->forceFill([
            'slug' => 'cu-' . uniqid(), 'name' => $name, 'cod' => $name,
            'country_id' => $countryId, 'is_active' => $active, 'tenant_id' => 1,
        ])->save();
        return $c;
    }

    public function test_sorts_by_country_name_ascending(): void
    {
        $this->actingAsTenantAdmin(1);
        // Nombres al revés del orden de país, para probar que NO ordena por nombre.
        $this->mk('Zeta', 2);   // Peru
        $this->mk('Alpha', 1);  // Argentina

        $res = $this->get(route('business_management.customers.index', [
            'sort' => 'country', 'direction' => 'asc',
        ]));

        // Argentina antes que Peru → Alpha, luego Zeta.
        $res->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('customers.data', fn ($rows) =>
                collect($rows)->pluck('name')->values()->all() === ['Alpha', 'Zeta'])
        );
    }

    public function test_sorts_by_country_name_descending(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->mk('Zeta', 2);   // Peru
        $this->mk('Alpha', 1);  // Argentina

        $res = $this->get(route('business_management.customers.index', [
            'sort' => 'country', 'direction' => 'desc',
        ]));

        $res->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('customers.data', fn ($rows) =>
                collect($rows)->pluck('name')->values()->all() === ['Zeta', 'Alpha'])
        );
    }

    public function test_country_sort_works_with_advanced_filter(): void
    {
        $this->actingAsTenantAdmin(1);
        $this->mk('Zeta', 2, true);    // Peru, activo
        $this->mk('Alpha', 1, true);   // Argentina, activo
        $this->mk('Gamma', 1, false);  // Argentina, INACTIVO → lo filtra

        // Filtro avanzado is_active = true + orden por país asc.
        $res = $this->get(route('business_management.customers.index', [
            'advanced_where' => json_encode([
                ['field' => 'is_active', 'op' => '=', 'value' => true],
            ]),
            'sort' => 'country', 'direction' => 'asc',
        ]));

        // Solo activos, ordenados por país: Alpha (AR), Zeta (PE). Gamma excluido.
        $res->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('customers.data', fn ($rows) =>
                collect($rows)->pluck('name')->values()->all() === ['Alpha', 'Zeta'])
        );
    }
}
