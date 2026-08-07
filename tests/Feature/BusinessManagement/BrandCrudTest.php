<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BrandCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['brands.view', 'brands.create', 'brands.edit', 'brands.delete', 'brands.show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);
    }

    public function test_admin_can_create_brand(): void
    {
        $response = $this->post(route('business_management.brands.store'), [
            'name' => 'Éster Natural', 'code' => 'ester_natural', 'is_active' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('brands', ['name' => 'Éster Natural', 'code' => 'ester_natural']);
    }

    public function test_empty_code_is_derived_from_name_lowercased(): void
    {
        $response = $this->post(route('business_management.brands.store'), [
            'name' => 'Megger Group', 'code' => '', 'is_active' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        // Código vacío → nombre en minúscula con espacios → guion bajo.
        $this->assertDatabaseHas('brands', ['name' => 'Megger Group', 'code' => 'megger_group']);
    }

    public function test_admin_can_update_brand(): void
    {
        $oil = Brand::create(['name' => 'Prueba', 'code' => 'prueba', 'is_active' => true]);

        $response = $this->put(route('business_management.brands.update', $oil->slug), [
            'name' => 'Prueba Editada', 'code' => 'prueba', 'is_active' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('brands', ['id' => $oil->id, 'name' => 'Prueba Editada', 'is_active' => false]);
    }

    public function test_duplicate_respects_plan_record_limit(): void
    {
        // Plan limitado a 1 registro por módulo (find()->update dispara el evento
        // que limpia el cache estático de Plan::findBySlug).
        \App\Models\Plan::find(1)->update(['max_records_per_module' => 1]);

        $brand = Brand::create(['name' => 'Marca 1', 'code' => 'marca-1', 'is_active' => true]);
        $this->assertSame(1, Brand::count());

        // Ya en el límite → duplicar debe bloquearse, sin crear el clon.
        $this->post(route('business_management.brands.duplicate', $brand))->assertRedirect();
        $this->assertSame(1, Brand::count());
    }

    public function test_name_is_unique_per_tenant(): void
    {
        Brand::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        // Per-tenant: el mismo nombre (case/accent insensitive) en el mismo
        // workspace se rechaza.
        $response = $this->post(route('business_management.brands.store'), [
            'name' => 'mineral', 'is_active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_same_name_allowed_in_another_tenant(): void
    {
        // Aislamiento cross-tenant: 'Mineral' existe en tenant 1; otro workspace
        // puede tener su propia 'Mineral' sin chocar (índice único es por tenant).
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        Brand::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]); // tenant 1 (actor)

        // BelongsToTenant fuerza el tenant del actor al crear; corregimos a 2 por DB.
        $other = User::factory()->create(['tenant_id' => 2, 'country_id' => 1, 'locale_id' => 1]);
        DB::table('users')->where('id', $other->id)->update(['tenant_id' => 2]);
        $other->refresh();
        $other->assignRole('admin');
        $this->actingAs($other);

        $response = $this->post(route('business_management.brands.store'), [
            'name' => 'Mineral', 'is_active' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(2, Brand::withoutGlobalScopes()->whereRaw('LOWER(name) = ?', ['mineral'])->count());
    }

    public function test_quick_store_creates_brand_and_returns_json(): void
    {
        $response = $this->postJson(route('business_management.brands.quick_store'), [
            'name' => 'ABB', 'code' => 'abb', 'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['id', 'name']);
        $this->assertSame('ABB', $response->json('name'));
        $this->assertDatabaseHas('brands', ['name' => 'ABB', 'code' => 'abb']);
    }

    public function test_quick_store_rejects_duplicate_name(): void
    {
        Brand::create(['name' => 'Siemens', 'code' => 'siemens', 'is_active' => true]);

        // Mismo nombre con otra capitalización → rechazado (422 con error en name).
        $response = $this->postJson(route('business_management.brands.quick_store'), [
            'name' => 'siemens', 'is_active' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_quick_store_blocked_without_permission(): void
    {
        // Usuario personalizado sin brands.create no puede dar de alta marcas.
        $role = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web'], ['description' => 'Test viewer']);
        $role->syncPermissions([Permission::firstOrCreate(['name' => 'brands.view', 'guard_name' => 'web'])]);
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('viewer');
        $this->actingAs($u);

        $response = $this->postJson(route('business_management.brands.quick_store'), [
            'name' => 'Hyundai', 'is_active' => true,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('brands', ['name' => 'Hyundai']);
    }

    public function test_update_to_existing_name_is_rejected(): void
    {
        Brand::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);
        $other = Brand::create(['name' => 'Silicona', 'code' => 'silicona', 'is_active' => true]);

        $response = $this->put(route('business_management.brands.update', $other->slug), [
            'name' => 'Mineral', 'code' => 'silicona', 'is_active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_import_crea_y_deduplica_por_tenant(): void
    {
        // El import crea/dedup dentro del tenant del actor (tenant 1).
        Brand::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        $importer = new \App\Imports\BusinessManagement\Brands\BrandsImport('update_or_create', false);
        $importer->collection(collect([
            collect(['name' => 'Girasol', 'is_active' => 1]),  // crea
            collect(['name' => 'mineral', 'is_active' => 0]),  // dedup en tenant -> actualiza
        ]));

        $this->assertSame(1, $importer->created);
        $this->assertSame(1, $importer->updated);
        $this->assertDatabaseHas('brands', ['name' => 'Girasol', 'tenant_id' => 1]);
        // Solo 2 brands en el tenant del actor (Mineral + Girasol).
        $this->assertCount(2, Brand::all());
    }

    public function test_import_maneja_code_y_deduplica_por_code(): void
    {
        Brand::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        $importer = new \App\Imports\BusinessManagement\Brands\BrandsImport('update_or_create', false);
        $importer->collection(collect([
            collect(['name' => 'Silicona', 'code' => 'silicona', 'is_active' => 1]), // crea con code
            collect(['name' => 'Otro',     'code' => 'mineral',  'is_active' => 1]), // code ya existe → error
        ]));

        $this->assertSame(1, $importer->created);
        $this->assertCount(1, $importer->errors);
        $this->assertDatabaseHas('brands', ['name' => 'Silicona', 'code' => 'silicona']);
        $this->assertDatabaseMissing('brands', ['name' => 'Otro']);
    }
}
