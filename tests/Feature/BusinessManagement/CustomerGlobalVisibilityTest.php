<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use App\Models\CustomerArea;
use App\Models\CustomerLocation;
use App\Models\CustomerSubstation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Clientes GLOBALES (workspace = Plataforma, tenant_id null).
 *
 * Desde que Customer usa BelongsToTenantOrGlobal, un cliente sin tenant lo crea
 * el super y lo VEN todos los workspaces (para asignarle transformadores), pero
 * solo el super lo edita/borra. Los clientes con tenant siguen aislados.
 */
class CustomerGlobalVisibilityTest extends CustomerTestCase
{
    /** Crea un cliente GLOBAL actuando como super (tenant_id null). */
    private function makeGlobalCustomer(string $name = 'Cliente Global'): Customer
    {
        $this->actingAsSuperAdmin();
        return Customer::create([
            'name' => $name, 'cod' => 'G-' . $name, 'country_id' => 2, 'is_active' => true,
        ]);
    }

    public function test_un_cliente_global_lo_ve_el_admin_de_cualquier_tenant(): void
    {
        $global = $this->makeGlobalCustomer();
        $this->assertNull($global->tenant_id, 'el super lo crea global (tenant_id null)');

        // Admin de Empresa 1 lo ve.
        $this->actingAsTenantAdmin(1);
        $this->assertTrue(Customer::query()->whereKey($global->id)->exists());

        // Admin de Empresa 2 también lo ve.
        $this->actingAsTenantAdmin(2);
        $this->assertTrue(Customer::query()->whereKey($global->id)->exists());
    }

    public function test_un_admin_no_puede_editar_un_cliente_global(): void
    {
        $global = $this->makeGlobalCustomer('Para editar');

        $this->actingAsTenantAdmin(1);
        $loaded = Customer::query()->whereKey($global->id)->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $loaded->update(['name' => 'Hackeado']);
    }

    public function test_la_jerarquia_de_un_cliente_global_la_ve_y_la_usa_el_admin(): void
    {
        // Super arma un cliente global CON su jerarquía (todo tenant_id null).
        $global = $this->makeGlobalCustomer('Cliente Global con sedes');
        $loc = CustomerLocation::create(['customer_id' => $global->id, 'name' => 'Sede']);
        $area = CustomerArea::create(['customer_location_id' => $loc->id, 'name' => 'Área']);
        $sub = CustomerSubstation::create(['customer_area_id' => $area->id, 'name' => 'Subestación']);
        $this->assertNull($sub->tenant_id, 'la jerarquía del cliente global nace global');

        // Un admin de otro tenant VE la subestación → puede colgarle un trafo.
        // (Sin esto, un cliente global sería inutilizable.)
        $this->actingAsTenantAdmin(2);
        $this->assertTrue(CustomerSubstation::query()->whereKey($sub->id)->exists());
        $this->assertTrue(CustomerLocation::query()->whereKey($loc->id)->exists());
    }

    public function test_los_clientes_con_tenant_siguen_aislados(): void
    {
        // Creamos ambos admin SIN auth activa: con un creador autenticado,
        // BelongsToTenant le forzaría al user nuevo el tenant del creador.
        $adminA = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $adminA->assignRole('admin');
        $adminB = User::factory()->create(['tenant_id' => 2, 'country_id' => 1, 'locale_id' => 1]);
        $adminB->assignRole('admin');

        // Cliente propio de Empresa 1.
        $this->actingAs($adminA);
        $own = Customer::create(['name' => 'Solo E1', 'cod' => 'E1', 'country_id' => 2, 'is_active' => true]);
        $this->assertSame(1, $own->tenant_id);

        // Empresa 2 NO lo ve (no es global).
        $this->actingAs($adminB);
        $this->assertFalse(Customer::query()->whereKey($own->id)->exists());
    }
}
