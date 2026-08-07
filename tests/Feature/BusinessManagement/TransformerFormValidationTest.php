<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TransformerFormValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode([]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'transformers.create', 'transformers.edit'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);
    }

    public function test_all_fields_are_required(): void
    {
        $this->post(route('business_management.transformers.store'), [])
            ->assertSessionHasErrors([
                'serial', 'tag', 'customer_id', 'customer_substation_id',
                'oil_type_id', 'transformer_type_id', 'brand_id', 'tap_changer_type_id',
                'voltage_kv', 'power_mva', 'manufacture_year',
            ]);
    }

    public function test_serial_plus_tag_must_be_unique_per_tenant(): void
    {
        // Trafo existente en el workspace: serie ABC, tag 123.
        DB::table('transformers')->insert([
            'slug' => Str::random(22), 'serial' => 'ABC', 'tag' => '123',
            'tenant_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Misma serie + MISMO tag → error en 'tag' (los demás campos faltan, pero
        // lo que verificamos es que la combinación dispare el error de unicidad).
        $this->post(route('business_management.transformers.store'), ['serial' => 'ABC', 'tag' => '123'])
            ->assertSessionHasErrors(['tag']);

        // Misma serie + tag DISTINTO → la combinación es válida (no error en 'tag').
        $this->post(route('business_management.transformers.store'), ['serial' => 'ABC', 'tag' => '456'])
            ->assertSessionDoesntHaveErrors(['tag']);
    }

    public function test_same_serial_tag_in_other_tenant_is_allowed(): void
    {
        // Otro tenant tiene ABC/123; el usuario (tenant 1) puede crear ABC/123.
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('transformers')->insert([
            'slug' => Str::random(22), 'serial' => 'ABC', 'tag' => '123',
            'tenant_id' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('business_management.transformers.store'), ['serial' => 'ABC', 'tag' => '123'])
            ->assertSessionDoesntHaveErrors(['tag']);
    }
}
