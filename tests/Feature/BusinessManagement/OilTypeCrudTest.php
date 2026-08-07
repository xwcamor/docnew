<?php

namespace Tests\Feature\BusinessManagement;

use App\Jobs\BusinessManagement\OilTypes\GenerateOilTypesExcelJob;
use App\Models\OilType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OilTypeCrudTest extends TestCase
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
        foreach (['oil_types.view', 'oil_types.create', 'oil_types.edit', 'oil_types.delete', 'oil_types.show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        // Tipos de aceite es catálogo interno del motor: SOLO super (las rutas van
        // dentro de un grupo role:super). super bypassa permisos vía Gate::before.
        $super = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $super->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');
        $this->actingAs($u);
    }

    public function test_admin_can_create_oil_type(): void
    {
        $response = $this->post(route('business_management.oil_types.store'), [
            'name' => 'Éster Natural', 'code' => 'ester_natural', 'is_active' => true,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('oil_types', ['name' => 'Éster Natural', 'code' => 'ester_natural']);
    }

    public function test_admin_can_update_oil_type(): void
    {
        $oil = OilType::create(['name' => 'Prueba', 'code' => 'prueba', 'is_active' => true]);

        $response = $this->put(route('business_management.oil_types.update', $oil->slug), [
            'name' => 'Prueba Editada', 'code' => 'prueba', 'is_active' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('oil_types', ['id' => $oil->id, 'name' => 'Prueba Editada', 'is_active' => false]);
    }

    public function test_update_clones_rules_from_another_oil(): void
    {
        // Aceite origen CON reglas (un cuadro de cromas mínimo).
        $this->seed(\Database\Seeders\DiagnosticCatalogSeeder::class);
        $cromas = \App\Models\Test::where('code', 'cromas')->value('id');
        $var    = \App\Models\Variable::query()->value('id');
        $source = OilType::create(['name' => 'Origen', 'code' => 'origen', 'is_active' => true]);
        $set = \App\Models\RuleSet::create([
            'test_id' => $cromas, 'oil_type_id' => $source->id, 'transformer_type_id' => null,
            'standard_id' => null, 'total_weight' => 2, 'label' => 'Base', 'is_active' => true,
        ]);
        $rule = \App\Models\Rule::create([
            'rule_set_id' => $set->id, 'variable_id' => $var, 'score' => 1, 'weight' => 2,
            'priority' => 1, 'result_label' => null, 'is_active' => true,
        ]);
        \App\Models\RuleCondition::create([
            'rule_id' => $rule->id, 'variable_id' => $var, 'operator' => '<=', 'value' => 100, 'sort_order' => 1,
        ]);

        // Aceite destino SIN reglas.
        $target = OilType::create(['name' => 'Demo', 'code' => 'demo', 'is_active' => true]);
        $this->assertDatabaseMissing('rule_sets', ['oil_type_id' => $target->id]);

        // Editar el destino eligiendo copiar reglas del origen.
        $this->put(route('business_management.oil_types.update', $target->slug), [
            'name' => 'Demo', 'code' => 'demo', 'is_active' => true,
            'clone_rules_from' => $source->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Ahora el destino tiene su propio cuadro clonado.
        $this->assertDatabaseHas('rule_sets', ['oil_type_id' => $target->id, 'test_id' => $cromas]);
    }

    public function test_index_exposes_has_rules_flag(): void
    {
        $this->seed(\Database\Seeders\DiagnosticCatalogSeeder::class);
        $cromas = \App\Models\Test::where('code', 'cromas')->value('id');
        $var    = \App\Models\Variable::query()->value('id');

        $withRules = OilType::create(['name' => 'Con Reglas', 'code' => 'con_reglas', 'is_active' => true]);
        $set = \App\Models\RuleSet::create([
            'test_id' => $cromas, 'oil_type_id' => $withRules->id, 'transformer_type_id' => null,
            'standard_id' => null, 'total_weight' => 2, 'label' => 'Base', 'is_active' => true,
        ]);
        \App\Models\Rule::create([
            'rule_set_id' => $set->id, 'variable_id' => $var, 'score' => 1, 'weight' => 2,
            'priority' => 1, 'result_label' => null, 'is_active' => true,
        ]);
        OilType::create(['name' => 'Sin Reglas', 'code' => 'sin_reglas', 'is_active' => true]);

        $this->get(route('business_management.oil_types.index'))
            ->assertInertia(fn ($p) => $p
                ->where('oilTypes.data', fn ($rows) => collect($rows)
                    ->firstWhere('name', 'Con Reglas')['has_rules'] == 1
                    && collect($rows)->firstWhere('name', 'Sin Reglas')['has_rules'] == 0)
                ->etc());
    }

    public function test_name_is_unique_globally(): void
    {
        OilType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        // Catalogo global: el mismo nombre (case/accent insensitive) se rechaza.
        $response = $this->post(route('business_management.oil_types.store'), [
            'name' => 'mineral', 'is_active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_update_to_existing_name_is_rejected(): void
    {
        OilType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);
        $other = OilType::create(['name' => 'Silicona', 'code' => 'silicona', 'is_active' => true]);

        $response = $this->put(route('business_management.oil_types.update', $other->slug), [
            'name' => 'Mineral', 'code' => 'silicona', 'is_active' => true,
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_import_crea_y_deduplica_global_sin_tenant(): void
    {
        // El import no debe tocar tenant_id (catalogo global). Antes reventaba
        // en Postgres por filtrar una columna inexistente.
        OilType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        $importer = new \App\Imports\BusinessManagement\OilTypes\OilTypesImport('update_or_create', false);
        $importer->collection(collect([
            collect(['name' => 'Girasol', 'is_active' => 1]),  // crea
            collect(['name' => 'mineral', 'is_active' => 0]),  // dedup global -> actualiza
        ]));

        $this->assertSame(1, $importer->created);
        $this->assertSame(1, $importer->updated);
        $this->assertDatabaseHas('oil_types', ['name' => 'Girasol']);
        // No existe columna tenant_id: si el import la tocara, esto reventaria.
        $this->assertCount(2, OilType::all());
    }

    public function test_import_maneja_code_y_deduplica_por_code(): void
    {
        OilType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true]);

        $importer = new \App\Imports\BusinessManagement\OilTypes\OilTypesImport('update_or_create', false);
        $importer->collection(collect([
            collect(['name' => 'Silicona', 'code' => 'silicona', 'is_active' => 1]), // crea con code
            collect(['name' => 'Otro',     'code' => 'mineral',  'is_active' => 1]), // code ya existe → error
        ]));

        $this->assertSame(1, $importer->created);
        $this->assertCount(1, $importer->errors);
        $this->assertDatabaseHas('oil_types', ['name' => 'Silicona', 'code' => 'silicona']);
        $this->assertDatabaseMissing('oil_types', ['name' => 'Otro']);
    }

    public function test_export_acepta_columna_sort_order(): void
    {
        // Regresión: el diálogo manda sort_order; el allowlist debe aceptarlo.
        Bus::fake();

        $response = $this->post(route('business_management.oil_types.export_excel'), [
            'columns' => ['name', 'code', 'sort_order', 'is_active', 'created_at'],
            'scope'   => 'all',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        Bus::assertDispatched(GenerateOilTypesExcelJob::class);
    }

    public function test_export_pdf_renderiza_sin_crashear(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        OilType::create(['name' => 'Mineral', 'code' => 'mineral', 'is_active' => true, 'sort_order' => 1]);

        $job = new \App\Jobs\BusinessManagement\OilTypes\GenerateOilTypesPdfJob(auth()->id(), [
            'scope'   => 'all',
            'columns' => ['name', 'code', 'sort_order', 'is_active'],
        ]);
        $job->handle();

        $download = \App\Models\Download::where('user_id', auth()->id())->latest('id')->first();
        $this->assertSame('ready', $download->status);
        $this->assertNotEmpty(\Illuminate\Support\Facades\Storage::disk('local')->get($download->path));
    }
}
