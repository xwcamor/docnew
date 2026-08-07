<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use App\Models\Transformer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 3ª capa de acceso: usuario restringido a ciertos clientes (customer_user).
 * - CON asignaciones → solo ve los trafos/clientes de esos clientes.
 * - SIN asignaciones → ve todo su tenant (comportamiento actual intacto).
 */
class CustomerScopedAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::disableForeignKeyConstraints();

        DB::table('languages')->insert(['id' => 1, 'slug' => Str::random(22), 'name' => 'ES', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('locales')->insert(['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'ES', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('regions')->insert(['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('countries')->insert(['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('tenants')->insert(['id' => 1, 'slug' => Str::random(22), 'name' => 'E1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        // Dos clientes + un trafo en cada uno.
        foreach ([['id' => 10, 'name' => 'Cliente A'], ['id' => 20, 'name' => 'Cliente B']] as $c) {
            DB::table('customers')->insert(['id' => $c['id'], 'slug' => Str::random(22), 'name' => $c['name'], 'is_active' => true, 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['id' => 100, 'cid' => 10, 's' => 'TR-A'], ['id' => 200, 'cid' => 20, 's' => 'TR-B']] as $t) {
            DB::table('transformers')->insert(['id' => $t['id'], 'slug' => Str::random(22), 'serial' => $t['s'], 'customer_id' => $t['cid'], 'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function user(): User
    {
        return User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
    }

    public function test_usuario_sin_asignaciones_ve_todo(): void
    {
        $this->actingAs($this->user());

        $this->assertSame(2, Transformer::count());
        $this->assertSame(2, Customer::count());
    }

    public function test_usuario_con_asignacion_ve_solo_su_cliente(): void
    {
        $u = $this->user();
        DB::table('customer_user')->insert(['user_id' => $u->id, 'customer_id' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($u);

        // Solo el trafo del cliente 10.
        $serials = Transformer::pluck('serial')->all();
        $this->assertSame(['TR-A'], $serials);

        // Solo el cliente 10.
        $this->assertSame([10], Customer::pluck('id')->map(fn ($i) => (int) $i)->all());

        // El trafo del otro cliente es invisible (404 por route binding).
        $this->assertNull(Transformer::find(200));
    }

    public function test_dos_clientes_asignados_ve_ambos(): void
    {
        $u = $this->user();
        DB::table('customer_user')->insert([
            ['user_id' => $u->id, 'customer_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $u->id, 'customer_id' => 20, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($u);

        $this->assertSame(2, Transformer::count());
    }
}
