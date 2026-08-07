<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Furano;
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

class FuranoCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Transformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['transformers.view', 'transformers.edit', 'transformers.create', 'transformers.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $admin->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->transformer->save();
    }

    public function test_create_diagnoses_dp_and_updates_health_index(): void
    {
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'fal' => 50, 'hme' => 10, 'ace' => 5, 'mfu' => 2, 'fua' => 8,
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $sample = Furano::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertSame('Muy Bueno', $sample->condition);
        $this->assertEqualsWithDelta(803, $sample->dp, 2);

        // Solo furanos presente, rating 4 -> HI 100.
        $this->transformer->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $this->transformer->health_index, 0.01);
    }

    public function test_update_and_delete_recompute(): void
    {
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'fal' => 50, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4,
        ]);
        $sample = Furano::where('transformer_id', $this->transformer->id)->firstOrFail();

        $this->put(route('business_management.transformers.furanos.update', [$this->transformer->slug, $sample->id]), [
            'sample_date' => '2024-09-04', 'fal' => 7000, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4,
        ])->assertRedirect();
        $this->assertSame('Muy Malo', $sample->fresh()->condition);

        $this->delete(route('business_management.transformers.furanos.destroy', [$this->transformer->slug, $sample->id]))
            ->assertRedirect();
        $this->assertSoftDeleted('furanos', ['id' => $sample->id]);
        $this->assertNull($this->transformer->fresh()->health_index);
    }

    public function test_fal_and_date_required(): void
    {
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04',
        ])->assertSessionHasErrors('fal');

        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'fal' => 50,
        ])->assertSessionHasErrors('sample_date');
    }

    public function test_only_fal_required_extras_optional(): void
    {
        // Solo fecha + fal (sin los compuestos secundarios) debe pasar.
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'fal' => 50,
        ])->assertSessionHasNoErrors();
    }

    public function test_batch_creates_updates_and_deletes(): void
    {
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'fal' => 50, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4,
        ]);
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-01-01', 'fal' => 20, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4,
        ]);
        $samples = Furano::where('transformer_id', $this->transformer->id)->orderBy('id')->get();
        [$toEdit, $toDelete] = [$samples[0], $samples[1]];

        $this->post(route('business_management.transformers.furanos.batch', $this->transformer->slug), [
            'upserts' => [
                ['id' => $toEdit->id, 'sample_date' => '2024-09-04', 'fal' => 7000, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4],
                ['id' => null, 'sample_date' => '2024-12-01', 'fal' => 60, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4],
            ],
            'deletes' => [$toDelete->id],
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $this->assertSame('Muy Malo', $toEdit->fresh()->condition);
        $this->assertSoftDeleted('furanos', ['id' => $toDelete->id]);
        $this->assertSame(2, Furano::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_batch_requires_fal_on_each_upsert(): void
    {
        $this->post(route('business_management.transformers.furanos.batch', $this->transformer->slug), [
            'upserts' => [['id' => null, 'sample_date' => '2024-09-04']],
        ])->assertSessionHasErrors('upserts.0.fal');
    }

    public function test_transformer_activity_includes_furano_events(): void
    {
        // Una acción en la pestaña Furanos debe aparecer en "Cambios" del trafo,
        // con un subject que la identifique como muestra de furanos.
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'fal' => 50, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4,
        ]);

        // Sin los redirects de localización (mismo patrón que CustomerTestCase),
        // para poder leer la respuesta Inertia del show directamente.
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $res = $this->get(route('business_management.transformers.show', $this->transformer->slug));
        $res->assertInertia(fn ($page) => $page->has('activity'));

        $activity = collect($res->viewData('page')['props']['activity']);
        $this->assertTrue(
            $activity->contains(fn ($a) => $a['event'] === 'created' && !empty($a['subject']) && str_contains($a['subject'], '2024-09-04')),
            'El feed del trafo debe incluir el alta de la muestra de furanos con subject.'
        );
    }

    public function test_show_includes_generation_rate(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        // Dos muestras: 100→200 ppb en ~6 meses ≈ +200 ppb/año, tendencia al alza.
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), ['sample_date' => '2024-01-01', 'fal' => 100, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4]);
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), ['sample_date' => '2024-07-01', 'fal' => 200, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4]);

        $res = $this->get(route('business_management.transformers.show', $this->transformer->slug));
        $trend = $res->viewData('page')['props']['furanosTrend'];

        $this->assertSame('up', $trend['direction']);
        $this->assertEqualsWithDelta(200, $trend['rate'], 15);
        // Última muestra 200 ppb = 0.2 ppm → Bueno (rating 3) → monitoreo de rutina.
        $this->assertSame('routine', $trend['recommendation']);
        $this->assertTrue($trend['rising']);
    }

    public function test_show_includes_co2_co_ratio(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        // CO=100, CO2=200 → ratio 2.0 < 3 → posible involucramiento del papel.
        (new \App\Models\Chromatographical())->forceFill([
            'transformer_id' => $this->transformer->id, 'sample_date' => '2024-09-04',
            'co' => 100, 'co2' => 200, 'tenant_id' => 1, 'created_by' => auth()->id(),
        ])->save();

        $res = $this->get(route('business_management.transformers.show', $this->transformer->slug));
        $co = $res->viewData('page')['props']['furanosCoRatio'];

        $this->assertEqualsWithDelta(2.0, $co['ratio'], 0.01);
        $this->assertSame('low', $co['level']);
    }

    public function test_show_includes_degradation_mechanism(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        // 5HMF (hme) dominante entre los secundarios → oxidación.
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'fal' => 50, 'hme' => 30, 'ace' => 5, 'mfu' => 2, 'fua' => 8,
        ]);

        $res = $this->get(route('business_management.transformers.show', $this->transformer->slug));
        $mech = $res->viewData('page')['props']['furanosMechanism'];

        $this->assertSame('hme', $mech['compound']);
        $this->assertSame('oxidation', $mech['mechanism']);
    }

    public function test_pdf_report_downloads(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        // Dos muestras → el reporte también genera los gráficos SVG de tendencia.
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), ['sample_date' => '2024-01-01', 'fal' => 50, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4]);
        $this->post(route('business_management.transformers.furanos.store', $this->transformer->slug), ['sample_date' => '2024-07-01', 'fal' => 300, 'hme' => 5, 'ace' => 3, 'mfu' => 1, 'fua' => 4]);

        // El PDF por prueba se eliminó: lo cubre el informe ÚNICO del trafo.
        $res = $this->get(route('business_management.transformers.report', $this->transformer->slug));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));

        // POST con una "foto" PNG (1x1) embebida → el reporte la incrusta.
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $post = $this->post(route('business_management.transformers.report', $this->transformer->slug), [
            'images' => [['label' => '2FAL (ppb)', 'group' => 'cromas', 'dataURL' => $png]],
        ]);
        $post->assertOk();
        $this->assertSame('application/pdf', $post->headers->get('content-type'));
    }

    public function test_explain_returns_trace_without_persisting(): void
    {
        $res = $this->postJson(route('business_management.transformers.furanos.explain', $this->transformer->slug), [
            'fal' => 50,
        ]);

        $res->assertOk();
        $res->assertJsonPath('furanos.has_value', true);
        $res->assertJsonPath('furanos.condition', 'Muy Bueno');
        $res->assertJsonPath('furanos.reference.formula.status', 'confirmed');
        $res->assertJsonPath('furanos.reference.scale.status', 'confirmed');
        // La cita viene de i18n (no del config): traducible por idioma.
        $this->assertStringContainsString('Chendong', (string) $res->json('furanos.reference.formula.citation'));
        $this->assertNotEmpty($res->json('furanos.reference.scale.citation'));
        $this->assertEqualsWithDelta(803, $res->json('furanos.dp'), 2);
        // El semáforo trae una sola banda marcada.
        $this->assertCount(1, collect($res->json('furanos.scale'))->where('matched', true));
        // No persiste.
        $this->assertSame(0, Furano::where('transformer_id', $this->transformer->id)->count());
    }
}
