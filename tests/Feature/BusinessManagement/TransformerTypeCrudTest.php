<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\TransformerType;
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

class TransformerTypeCrudTest extends TestCase
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
        foreach (['transformer_types.view', 'transformer_types.create', 'transformer_types.edit', 'transformer_types.delete', 'transformer_types.show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        // Tipo de trafo es catálogo interno del motor: SOLO super (las rutas van
        // dentro de un grupo role:super). super bypassa permisos vía Gate::before.
        $super = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $super->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');
        $this->actingAs($u);
    }

    public function test_admin_can_create_brand(): void
    {
        $response = $this->post(route('business_management.transformer_types.store'), [
            'name' => 'Éster Natural', 'code' => 'ester_natural', 'is_active' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('transformer_types', ['name' => 'Éster Natural', 'code' => 'ester_natural']);
    }

    public function test_admin_can_update_brand(): void
    {
        $oil = TransformerType::create(['name' => 'Prueba', 'code' => 'prueba', 'is_active' => true]);

        $response = $this->put(route('business_management.transformer_types.update', $oil->slug), [
            'name' => 'Prueba Editada', 'code' => 'prueba', 'is_active' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('transformer_types', ['id' => $oil->id, 'name' => 'Prueba Editada', 'is_active' => false]);
    }

    public function test_name_is_unique_globally(): void
    {
        TransformerType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        // Catalogo global: el mismo nombre (case/accent insensitive) se rechaza.
        $response = $this->post(route('business_management.transformer_types.store'), [
            'name' => 'mineral', 'is_active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_update_to_existing_name_is_rejected(): void
    {
        TransformerType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);
        $other = TransformerType::create(['name' => 'Silicona', 'code' => 'silicona', 'is_active' => true]);

        $response = $this->put(route('business_management.transformer_types.update', $other->slug), [
            'name' => 'Mineral', 'code' => 'silicona', 'is_active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_import_crea_y_deduplica_global_sin_tenant(): void
    {
        // El import no debe tocar tenant_id (catalogo global). Antes reventaba
        // en Postgres por filtrar una columna inexistente.
        TransformerType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        $importer = new \App\Imports\BusinessManagement\TransformerTypes\TransformerTypesImport('update_or_create', false);
        $importer->collection(collect([
            collect(['name' => 'Girasol', 'is_active' => 1]),  // crea
            collect(['name' => 'mineral', 'is_active' => 0]),  // dedup global -> actualiza
        ]));

        $this->assertSame(1, $importer->created);
        $this->assertSame(1, $importer->updated);
        $this->assertDatabaseHas('transformer_types', ['name' => 'Girasol']);
        // No existe columna tenant_id: si el import la tocara, esto reventaria.
        $this->assertCount(2, TransformerType::all());
    }

    public function test_import_maneja_code_y_deduplica_por_code(): void
    {
        TransformerType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        $importer = new \App\Imports\BusinessManagement\TransformerTypes\TransformerTypesImport('update_or_create', false);
        $importer->collection(collect([
            collect(['name' => 'Silicona', 'code' => 'silicona', 'is_active' => 1]), // crea con code
            collect(['name' => 'Otro',     'code' => 'mineral',  'is_active' => 1]), // code ya existe → error
        ]));

        $this->assertSame(1, $importer->created);
        $this->assertCount(1, $importer->errors);
        $this->assertDatabaseHas('transformer_types', ['name' => 'Silicona', 'code' => 'silicona']);
        $this->assertDatabaseMissing('transformer_types', ['name' => 'Otro']);
    }
}
