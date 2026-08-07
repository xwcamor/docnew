<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Chromatographical;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected Transformer $transformer;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'transformers.view', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $this->admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);

        $this->transformer = $this->makeTransformer(1, 'S1', 'TR-1');
        // 2 muestras: Gases exige >=2 para dibujar tendencia.
        foreach (['2024-01-01', '2024-06-01'] as $d) {
            Chromatographical::create([
                'transformer_id' => $this->transformer->id, 'sample_date' => $d,
                'h2' => 10, 'ch4' => 5, 'c2h4' => 4, 'c2h2' => 0, 'c2h6' => 2, 'co' => 100, 'co2' => 1000, 'o2' => 2000, 'n2' => 50000,
                'tenant_id' => 1, 'created_by' => $this->admin->id,
            ]);
        }
    }

    private function makeTransformer(int $tenantId, string $serial, string $tag): Transformer
    {
        $t = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => $serial, 'tag' => $tag,
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => $tenantId, 'created_by' => $this->admin->id,
        ]);
        $t->save();
        // BelongsToTenant fuerza el tenant del actor; corregir por DB para el ajeno.
        DB::table('transformers')->where('id', $t->id)->update(['tenant_id' => $tenantId]);
        return $t;
    }

    public function test_index_returns_options_and_compared_data(): void
    {
        $res = $this->get(route('business_management.comparison.gases', ['ids' => [$this->transformer->id]]));
        $res->assertOk();

        $props = $res->viewData('page')['props'];
        $this->assertSame('gases', $props['mode']);
        $this->assertSame(10, $props['cap']);   // gráficos de línea: tope 10
        $this->assertNotEmpty($props['transformerOptions']);
        $this->assertCount(1, $props['data']);
        $this->assertSame($this->transformer->id, $props['data'][0]['id']);
        $this->assertCount(2, $props['data'][0]['samples']);
        $this->assertEqualsWithDelta(10, $props['data'][0]['samples'][0]['h2'], 0.001);
    }

    public function test_only_transformers_with_tests_are_listed(): void
    {
        // Un trafo SIN cromatografía no debe aparecer en las opciones.
        $noTests = $this->makeTransformer(1, 'NO-TESTS', 'TR-X');

        $res = $this->get(route('business_management.comparison.gases'));
        $values = collect($res->viewData('page')['props']['transformerOptions'])->pluck('value');

        $this->assertContains($this->transformer->id, $values->all());
        $this->assertNotContains($noTests->id, $values->all());
    }

    public function test_label_is_serial_only(): void
    {
        $res = $this->get(route('business_management.comparison.gases'));
        $opt = collect($res->viewData('page')['props']['transformerOptions'])
            ->firstWhere('value', $this->transformer->id);
        // Solo el serial, sin el tag.
        $this->assertSame('S1', $opt['label']);
    }

    public function test_gases_requires_two_samples(): void
    {
        // Trafo con 1 sola muestra: NO entra a Gases (necesita >=2)...
        $one = $this->makeTransformer(1, 'ONE-SAMPLE', 'TR-1S');
        Chromatographical::create([
            'transformer_id' => $one->id, 'sample_date' => '2024-03-01',
            'h2' => 5, 'ch4' => 5, 'c2h4' => 5, 'c2h2' => 0, 'c2h6' => 5, 'co' => 50, 'co2' => 500, 'o2' => 1000, 'n2' => 40000,
            'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);

        $gases = collect($this->get(route('business_management.comparison.gases'))
            ->viewData('page')['props']['transformerOptions'])->pluck('value');
        $this->assertNotContains($one->id, $gases->all());

        // ...pero SÍ entra a Patrones (alcanza con 1).
        $patrones = collect($this->get(route('business_management.comparison.patrones'))
            ->viewData('page')['props']['transformerOptions'])->pluck('value');
        $this->assertContains($one->id, $patrones->all());
    }

    public function test_customer_param_loads_whole_fleet(): void
    {
        $customer = (new \App\Models\Customer())->forceFill([
            'slug' => 'c-' . uniqid(), 'name' => 'Cliente Flota', 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ]);
        $customer->save();
        DB::table('transformers')->where('id', $this->transformer->id)->update(['customer_id' => $customer->id]);

        $res = $this->get(route('business_management.comparison.gases', ['customer' => $customer->id]));
        $res->assertOk();
        $props = $res->viewData('page')['props'];
        $this->assertSame([$this->transformer->id], $props['selected']);
        $this->assertCount(1, $props['data']);
    }

    public function test_cannot_compare_transformers_outside_scope(): void
    {
        $other = $this->makeTransformer(2, 'S2', 'TR-2'); // tenant ajeno
        Chromatographical::withoutGlobalScopes()->create([
            'transformer_id' => $other->id, 'sample_date' => '2024-02-01',
            'h2' => 99, 'ch4' => 99, 'c2h4' => 99, 'c2h2' => 9, 'c2h6' => 9, 'co' => 9, 'co2' => 9, 'o2' => 9, 'n2' => 9,
            'tenant_id' => 2, 'created_by' => $this->admin->id,
        ]);

        // El admin del tenant 1 pide el trafo del tenant 2 → no debe verlo.
        $res = $this->get(route('business_management.comparison.gases', ['ids' => [$other->id]]));
        $res->assertOk();
        $this->assertCount(0, $res->viewData('page')['props']['data']);
    }

    public function test_patrones_mode_builds_diagnostic_matrix(): void
    {
        // Cachear condición/HI en el trafo (lo que la matriz lee).
        DB::table('transformers')->where('id', $this->transformer->id)->update([
            'health_index' => 100, 'health_rating' => 4, 'fault_type' => 'normal', 'ieee_condition' => 1,
        ]);

        $res = $this->get(route('business_management.comparison.patrones', ['ids' => [$this->transformer->id]]));
        $res->assertOk();

        $props = $res->viewData('page')['props'];
        $this->assertSame('patrones', $props['mode']);
        $this->assertSame(40, $props['cap']);   // matriz: escala a muchos
        $this->assertCount(1, $props['matrix']);
        $row = $props['matrix'][0];
        $this->assertSame($this->transformer->id, $row['id']);
        $this->assertSame(4, $row['hi_rating']);
        $this->assertSame(1, $row['ieee']);
        $this->assertArrayHasKey('rogers', $row);
        $this->assertArrayHasKey('doernenburg', $row);
        // En modo patrones no se mandan las series de gases (eso es modo gases).
        $this->assertEmpty($props['data']);
    }
}
