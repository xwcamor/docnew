<?php

namespace Tests\Feature\BusinessManagement;

use App\Imports\BusinessManagement\Customers\CustomersImport;
use App\Jobs\BusinessManagement\Customers\GenerateCustomersCsvJob;
use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aislamiento cross-tenant de import y export de Customers.
 *
 * El import corre en el request (auth presente → BelongsToTenant autorellena el
 * tenant del actor). El export async corre en el worker (sin sesión), así que el
 * job captura el tenant_id del que dispara y filtra a mano. Estos tests prueban
 * que un usuario del tenant 2 NUNCA toca ni ve datos del tenant 1.
 */
class CustomerTenantIsolationTest extends CustomerTestCase
{
    /** Inserta un customer directo (sin pasar por el trait) en un tenant dado. */
    private function rawCustomer(int $id, int $tenantId, string $name, ?string $cod, int $countryId = 2): void
    {
        DB::table('customers')->insert([
            'id' => $id, 'slug' => Str::random(22), 'tenant_id' => $tenantId,
            'country_id' => $countryId, 'cod' => $cod, 'name' => $name,
            'is_active' => true, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_import_como_tenant2_no_toca_al_tenant1(): void
    {
        // El tenant 1 ya tiene un cliente con el mismo nombre y cod (mismo país PE).
        $this->rawCustomer(1000, 1, 'Compartido', 'RUC9', 2);

        // Un admin del tenant 2 importa ese mismo nombre/cod.
        $this->actingAsTenantAdmin(2);

        $import = new CustomersImport(mode: 'update_or_create', dryRun: false);
        $import->collection(new Collection([
            ['name' => 'Compartido', 'cod' => 'RUC9', 'country_iso' => 'PE'],
        ]));

        // Debe CREAR en el tenant 2 (no actualizar el del tenant 1).
        $this->assertSame(1, $import->created);
        $this->assertSame(0, $import->updated);

        // El registro del tenant 1 sigue intacto y aparece uno nuevo en el tenant 2.
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', 1)->count());
        $this->assertSame(1, Customer::withoutGlobalScopes()->where('tenant_id', 2)->count());
        $this->assertDatabaseHas('customers', ['name' => 'Compartido', 'tenant_id' => 2, 'cod' => 'RUC9']);
    }

    public function test_export_job_de_tenant2_solo_incluye_su_tenant(): void
    {
        $this->rawCustomer(1001, 1, 'Cliente Uno T1', 'RUC-A', 2);
        $this->rawCustomer(1002, 2, 'Cliente Dos T2', 'RUC-B', 2);

        // El job captura el tenant_id del user que dispara.
        $u2 = $this->actingAsTenantAdmin(2);
        $job = new GenerateCustomersCsvJob($u2->id, ['scope' => 'all', 'columns' => ['name']]);

        $build = new \ReflectionMethod($job, 'buildQuery');
        $build->setAccessible(true);
        $rows = $build->invoke($job)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Cliente Dos T2', $rows->first()->name);
        $this->assertSame(2, (int) $rows->first()->tenant_id);
    }
}
