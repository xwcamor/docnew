<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Chromatographical;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\CromasRulesSeeder;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChromatographicalCrudTest extends TestCase
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
        $this->seed(CromasRulesSeeder::class);

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

    /** La página show del trafo registra una "vista reciente" (para el buscador). */
    public function test_show_tracks_recent_view(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $this->assertDatabaseMissing('user_recent_views', [
            'viewable_type' => Transformer::class,
            'viewable_id'   => $this->transformer->id,
        ]);

        $this->get(route('business_management.transformers.show', $this->transformer->slug))->assertOk();

        $this->assertDatabaseHas('user_recent_views', [
            'viewable_type' => Transformer::class,
            'viewable_id'   => $this->transformer->id,
        ]);
    }

    public function test_create_diagnoses_and_updates_health_index(): void
    {
        // Cromas en el mejor tramo (score 1) -> "Muy Bueno", HI cromas-only = 100.
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04',
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10, 'o2' => 2000, 'n2' => 50000,
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $sample = Chromatographical::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertSame('Muy Bueno', $sample->dgaf_condition);

        $this->transformer->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $this->transformer->health_index, 0.01);
        $this->assertSame(4, $this->transformer->health_rating);
    }

    public function test_update_and_delete_recompute(): void
    {
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200,
            'c2h6' => 50, 'co' => 500, 'co2' => 10000, 'c2h2' => 10, 'o2' => 2000, 'n2' => 50000,
        ]);
        $sample = Chromatographical::where('transformer_id', $this->transformer->id)->firstOrFail();

        // Editar a gases altos -> peor condición.
        $this->put(route('business_management.transformers.cromas.update', [$this->transformer->slug, $sample->id]), [
            'sample_date' => '2024-09-04', 'h2' => 800, 'ch4' => 700, 'c2h4' => 600,
            'c2h6' => 350, 'co' => 1500, 'co2' => 19000, 'c2h2' => 90, 'o2' => 2000, 'n2' => 50000,
        ])->assertRedirect();
        $this->assertSame('Muy Malo', $sample->fresh()->dgaf_condition);

        // Borrar -> soft delete + HI sin datos.
        $this->delete(route('business_management.transformers.cromas.destroy', [$this->transformer->slug, $sample->id]))
            ->assertRedirect();
        $this->assertSoftDeleted('chromatographicals', ['id' => $sample->id]);
        $this->assertNull($this->transformer->fresh()->health_index);
    }

    public function test_sample_date_is_required(): void
    {
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'h2' => 100,
        ])->assertSessionHasErrors('sample_date');
    }

    public function test_all_gases_are_required(): void
    {
        // Solo fecha + h2: deben fallar los 8 gases faltantes (los 9 son obligatorios).
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'h2' => 100,
        ])->assertSessionHasErrors(['o2', 'n2', 'ch4', 'co', 'co2', 'c2h4', 'c2h6', 'c2h2']);
    }

    public function test_activity_humanizes_cromas_diff(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        $base = [
            'sample_date' => '2024-09-04', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10, 'o2' => 2000, 'n2' => 50000,
        ];
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), $base);
        $sample = Chromatographical::where('transformer_id', $this->transformer->id)->firstOrFail();

        // Editar H2: 100 -> 800.
        $this->put(route('business_management.transformers.cromas.update', [$this->transformer->slug, $sample->id]),
            array_merge($base, ['h2' => 800]));

        $res = $this->get(route('business_management.transformers.show', $this->transformer->slug));
        $activity = collect($res->viewData('page')['props']['activity']);
        $edit = $activity->firstWhere('event', 'updated');

        $this->assertNotNull($edit);
        $this->assertIsArray($edit['changes']);
        $row = collect($edit['changes'])->firstWhere('label', 'H₂');
        $this->assertNotNull($row, 'El diff del historial debe traer H₂ legible.');
        $this->assertSame('100', (string) $row['before']);
        $this->assertSame('800', (string) $row['after']);
    }

    public function test_batch_rejects_more_than_max_rows(): void
    {
        // Anti copy-paste masivo: más de 500 filas por guardado se rechaza.
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['id' => null, 'sample_date' => '2024-01-01'];
        }
        $this->post(route('business_management.transformers.cromas.batch', $this->transformer->slug), [
            'upserts' => $rows,
        ])->assertSessionHasErrors('upserts');

        $this->assertSame(0, Chromatographical::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_duplicate_sample_date_is_rejected(): void
    {
        $payload = [
            'sample_date' => '2024-09-04', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
            'co' => 500, 'co2' => 10000, 'c2h2' => 10, 'o2' => 2000, 'n2' => 50000,
        ];
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), $payload)
            ->assertSessionHasNoErrors();
        // Misma fecha otra vez -> rechazado.
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), $payload)
            ->assertSessionHasErrors('sample_date');
    }

    public function test_batch_creates_updates_and_deletes_in_one_request(): void
    {
        // Una muestra existente para editar y otra para borrar.
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200,
            'c2h6' => 50, 'co' => 500, 'co2' => 10000, 'c2h2' => 10, 'o2' => 2000, 'n2' => 50000,
        ]);
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-01-01', 'h2' => 5, 'ch4' => 5, 'c2h4' => 5, 'c2h6' => 5,
            'co' => 50, 'co2' => 500, 'c2h2' => 0, 'o2' => 2000, 'n2' => 50000,
        ]);
        $samples = Chromatographical::where('transformer_id', $this->transformer->id)->orderBy('id')->get();
        [$toEdit, $toDelete] = [$samples[0], $samples[1]];

        $this->post(route('business_management.transformers.cromas.batch', $this->transformer->slug), [
            'upserts' => [
                // Editar la primera a gases altos.
                ['id' => $toEdit->id, 'sample_date' => '2024-09-04', 'h2' => 800, 'ch4' => 700,
                 'c2h4' => 600, 'c2h6' => 350, 'co' => 1500, 'co2' => 19000, 'c2h2' => 90, 'o2' => 2000, 'n2' => 50000],
                // Crear una nueva (id nulo).
                ['id' => null, 'sample_date' => '2024-12-01', 'h2' => 120, 'ch4' => 110, 'c2h4' => 210,
                 'c2h6' => 55, 'co' => 520, 'co2' => 10200, 'c2h2' => 12, 'o2' => 2000, 'n2' => 50000],
            ],
            'deletes' => [$toDelete->id],
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $this->assertSame('Muy Malo', $toEdit->fresh()->dgaf_condition);
        $this->assertSoftDeleted('chromatographicals', ['id' => $toDelete->id]);
        $this->assertSame(2, Chromatographical::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_batch_requires_date_on_each_upsert(): void
    {
        $this->post(route('business_management.transformers.cromas.batch', $this->transformer->slug), [
            'upserts' => [['id' => null, 'h2' => 100]],
        ])->assertSessionHasErrors('upserts.0.sample_date');
    }

    public function test_explain_returns_full_trace(): void
    {
        $res = $this->postJson(route('business_management.transformers.cromas.explain', $this->transformer->slug), [
            'row' => [
                'sample_date' => '2024-09-04', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200,
                'c2h6' => 50, 'co' => 500, 'co2' => 10000, 'c2h2' => 10,
            ],
        ]);

        $res->assertOk();
        $res->assertJsonPath('cromas.has_rules', true);
        $res->assertJsonPath('cromas.condition', 'Muy Bueno');
        // El cuadro elegido (norma + aceite) y la traza por gas.
        $res->assertJsonPath('cromas.rule_set.oil', 'Mineral');
        $res->assertJson(fn ($j) => $j->has('cromas.per_gas')->has('cromas.scale')->etc());
        // El semáforo trae exactamente una banda marcada (donde cae el DGAF).
        $matched = collect($res->json('cromas.scale'))->where('matched', true);
        $this->assertCount(1, $matched);
        $this->assertSame('Muy Bueno', $matched->first()['condition']);
    }

    public function test_preview_diagnoses_without_persisting(): void
    {
        $res = $this->postJson(route('business_management.transformers.cromas.preview', $this->transformer->slug), [
            'rows' => [
                ['key' => 'a', 'sample_date' => '2024-09-04', 'h2' => 100, 'ch4' => 100, 'c2h4' => 200,
                 'c2h6' => 50, 'co' => 500, 'co2' => 10000, 'c2h2' => 10],
                ['key' => 'b', 'sample_date' => '2024-09-04', 'h2' => 800, 'ch4' => 700, 'c2h4' => 600,
                 'c2h6' => 350, 'co' => 1500, 'co2' => 19000, 'c2h2' => 90],
            ],
        ]);

        $res->assertOk();
        $res->assertJsonPath('rows.0.key', 'a');
        $res->assertJsonPath('rows.0.condition', 'Muy Bueno');
        $res->assertJsonPath('rows.1.condition', 'Muy Malo');

        // No persiste nada.
        $this->assertSame(0, Chromatographical::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_pdf_report_downloads(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        // Flujo de firmas: un usuario con auto-firma pre-autorizada + un
        // externo. El audit debe registrar cada slot y quién salió auto-firmado.
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put(
            'signatures/x/firma.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
        );
        $approver = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1, 'name' => 'Ing. Aprobadora']);
        $approver->forceFill(['signature' => 'signatures/x/firma.png', 'auto_sign_reports' => true])->save();
        \App\Models\Tenant::find(1)->reportSigners()->createMany([
            ['user_id' => $approver->id, 'title' => 'Supervisor', 'sort_order' => 1],
            ['user_id' => null, 'name' => 'C.P. Pedro Auditor', 'title' => 'Auditor', 'sort_order' => 2],
        ]);
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04',
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50, 'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);

        // El PDF por prueba se eliminó: el informe ÚNICO del trafo lo cubre.
        $res = $this->get(route('business_management.transformers.report', $this->transformer->slug));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));

        // Cada informe emitido queda auditado con sus códigos de trazabilidad.
        $log = \App\Models\AuditLog::where('event', 'report_generated')
            ->where('auditable_type', Transformer::class)
            ->where('auditable_id', $this->transformer->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertStringStartsWith('INF-', $log->new_values['report_code'] ?? '');
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $log->new_values['verify_code'] ?? '');
        // Flujo de firmas en el audit: cargo + nombre + quién salió auto-firmado.
        $signers = $log->new_values['signers'] ?? [];
        $this->assertCount(2, $signers);
        $this->assertSame(['Supervisor', 'Auditor'], array_column($signers, 'title'));
        $this->assertSame('Ing. Aprobadora', $signers[0]['name']);
        $this->assertTrue($signers[0]['auto_signed']);  // pre-autorizado: estampada
        $this->assertFalse($signers[1]['auto_signed']); // externo: firma a mano

        // El portal público de verificación reconoce el código (con o sin guiones,
        // sin auth) y rechaza códigos inexistentes.
        auth()->logout();
        $vc = $log->new_values['verify_code'];
        $this->get('/verify/' . $vc)->assertOk()
            ->assertSee(__('transformers.report.verify_ok'))
            ->assertSee($log->new_values['report_code']);
        $this->get('/verify/' . strtolower(str_replace('-', '', $vc)))->assertOk()
            ->assertSee(__('transformers.report.verify_ok'));
        $this->get('/verify/0000-0000-0000')->assertOk()
            ->assertSee(__('transformers.report.verify_fail'));
    }

    public function test_show_page_backfills_chart_cache(): void
    {
        // El caché vive en disk local: fake para aislar de corridas anteriores.
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        // Sin caché → Show avisa al front que lo repueble.
        $this->get(route('business_management.transformers.show', $this->transformer->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('chartCacheStale', true));

        // El front manda los gráficos capturados → quedan cacheados.
        $this->postJson(route('business_management.transformers.report_charts', $this->transformer->slug), [
            'images' => [['group' => 'cromas', 'label' => 'H2', 'dataURL' => $png]],
        ])->assertOk()->assertJsonPath('cached', 1);

        $cached = app(\App\Services\Reports\ReportChartCache::class)->get($this->transformer->fresh());
        $this->assertNotNull($cached);
        $this->assertSame('H2', $cached[0]['label']);

        // Con caché vigente, Show ya no pide repoblar.
        $this->get(route('business_management.transformers.show', $this->transformer->slug))
            ->assertInertia(fn ($page) => $page->where('chartCacheStale', false));
    }

    public function test_consolidated_transformer_report_downloads(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        $this->post(route('business_management.transformers.cromas.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04',
            'h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50, 'co' => 500, 'co2' => 10000, 'c2h2' => 10,
        ]);

        $res = $this->get(route('business_management.transformers.report', $this->transformer->slug));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    /**
     * Regresión: muchos seriales reales traen "/" (ej. ET09618/1). El nombre del
     * archivo de descarga debe sanitizarse o Symfony lanza 500 en el
     * Content-Disposition. reportFilename() lo cubre.
     */
    public function test_report_filename_tolerates_slash_in_serial(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $tr = (new Transformer())->forceFill([
            'slug' => 'tr-slash', 'serial' => 'ET09618/1', 'tag' => 'X',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => auth()->id(),
        ]);
        $tr->save();

        $this->assertSame('cromas-ET09618-1.pdf', $tr->reportFilename('cromas'));
        $this->get(route('business_management.transformers.report', $tr->slug))->assertOk();
    }
}
