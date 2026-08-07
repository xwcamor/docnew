<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Transformer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'ES', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'T1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'T2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'customers.view'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web'], ['description' => 'u']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    private function actor(string $role, array $perms = [], int $tenant = 1): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($role);
        $u->givePermissionTo($perms);
        return $u;
    }

    public function test_searches_transformers_and_customers_of_own_tenant(): void
    {
        $cust = Customer::create(['slug' => 'c1', 'name' => 'Minera Andina', 'tenant_id' => 1, 'created_by' => 1]);
        Transformer::create(['slug' => 'tr1', 'serial' => 'ABC-123', 'tag' => 'T1', 'customer_id' => $cust->id, 'tenant_id' => 1, 'created_by' => 1]);
        // Otro tenant: no debe aparecer.
        Transformer::create(['slug' => 'tr2', 'serial' => 'ABC-999', 'tag' => 'T9', 'tenant_id' => 2, 'created_by' => 1]);

        $this->actingAs($this->actor('admin', ['transformers.view', 'customers.view']));

        $res = $this->getJson(route('search', ['q' => 'ABC']))->assertOk();
        $res->assertJsonCount(1, 'transformers');
        $res->assertJsonPath('transformers.0.serial', 'ABC-123');

        $this->getJson(route('search', ['q' => 'Andina']))->assertOk()->assertJsonPath('customers.0.name', 'Minera Andina');
    }

    public function test_finds_by_brand_substation_and_health_state(): void
    {
        $brand = \App\Models\Brand::create(['slug' => 'b1', 'name' => 'ABB', 'tenant_id' => 1, 'created_by' => 1]);
        Transformer::create(['slug' => 'tr-abb', 'serial' => 'S-100', 'tag' => 'T', 'brand_id' => $brand->id, 'tenant_id' => 1, 'created_by' => 1]);
        // health_rating lo setea el motor (1 = Malo). 4=Muy Bueno … 0=Muy Malo.
        (new Transformer())->forceFill(['slug' => 'tr-bad', 'serial' => 'S-200', 'tag' => 'T', 'health_rating' => 1, 'health_index' => 40, 'tenant_id' => 1, 'created_by' => 1])->save();

        $this->actingAs($this->actor('admin', ['transformers.view', 'customers.view']));

        // Por marca.
        $this->getJson(route('search', ['q' => 'ABB']))->assertOk()
            ->assertJsonPath('transformers.0.serial', 'S-100')
            ->assertJsonPath('transformers.0.brand', 'ABB');

        // Por estado de salud ("malo" → Crítico/Malo label) devuelve el trafo en Malo con color.
        $res = $this->getJson(route('search', ['q' => 'malo']))->assertOk();
        $serials = collect($res->json('transformers'))->pluck('serial');
        $this->assertTrue($serials->contains('S-200'));
        $this->assertSame('orange', collect($res->json('transformers'))->firstWhere('serial', 'S-200')['color']);
    }

    public function test_respects_permissions_and_min_length(): void
    {
        Transformer::create(['slug' => 'tr1', 'serial' => 'XYZ-1', 'tag' => 'T', 'tenant_id' => 1, 'created_by' => 1]);

        // Sin permiso de transformers → no devuelve transformers.
        $this->actingAs($this->actor('user', []));
        $this->getJson(route('search', ['q' => 'XYZ']))->assertOk()->assertJsonCount(0, 'transformers');

        // Query muy corta → vacío.
        $this->actingAs($this->actor('admin', ['transformers.view']));
        $this->getJson(route('search', ['q' => 'X']))->assertOk()->assertJsonCount(0, 'transformers');
    }
}
