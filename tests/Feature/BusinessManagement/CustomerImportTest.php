<?php

namespace Tests\Feature\BusinessManagement;

use App\Imports\BusinessManagement\Customers\CustomersImport;
use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Import de Customers — comportamiento del país (ahora obligatorio al crear).
 */
class CustomerImportTest extends CustomerTestCase
{
    public function test_country_required_to_create_and_invalid_iso_is_rejected(): void
    {
        $this->actingAsTenantAdmin(1);

        $import = new CustomersImport(mode: 'update_or_create', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'Con Pais',  'cod' => 'RUC-CP', 'country_iso' => 'AR', 'is_active' => '1'],
            ['name' => 'Sin Pais',  'cod' => 'RUC-SP', 'country_iso' => '',   'is_active' => '1'],
            ['name' => 'Pais Malo', 'cod' => 'RUC-PM', 'country_iso' => 'ZZ', 'is_active' => '1'],
        ]));

        $this->assertSame(1, $import->created);
        $this->assertCount(2, $import->errors);
        $this->assertDatabaseHas('customers', ['name' => 'Con Pais', 'country_id' => 1, 'tenant_id' => 1]);
        $this->assertDatabaseMissing('customers', ['name' => 'Sin Pais']);
        $this->assertDatabaseMissing('customers', ['name' => 'Pais Malo']);
    }

    public function test_cod_unique_per_country_in_import(): void
    {
        $this->actingAsTenantAdmin(1);

        $import = new CustomersImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'A Argentina', 'cod' => 'RUC1', 'country_iso' => 'AR'],
            ['name' => 'B Peru',      'cod' => 'RUC1', 'country_iso' => 'PE'], // mismo cod, otro país → OK
            ['name' => 'C Argentina', 'cod' => 'RUC1', 'country_iso' => 'AR'], // choca con la fila 1 → error
        ]));

        $this->assertSame(2, $import->created);
        $this->assertCount(1, $import->errors);
        $this->assertDatabaseHas('customers', ['name' => 'A Argentina', 'cod' => 'RUC1', 'country_id' => 1]);
        $this->assertDatabaseHas('customers', ['name' => 'B Peru', 'cod' => 'RUC1', 'country_id' => 2]);
        $this->assertDatabaseMissing('customers', ['name' => 'C Argentina']);
    }

    public function test_valid_iso_resolves_country(): void
    {
        $this->actingAsTenantAdmin(1);

        $import = new CustomersImport(mode: 'create_only', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'Peruana', 'cod' => 'RUC-PER', 'country_iso' => 'PE', 'is_active' => '1'],
        ]));

        $this->assertSame(1, $import->created);
        $this->assertDatabaseHas('customers', ['name' => 'Peruana', 'country_id' => 2]);
    }
}
