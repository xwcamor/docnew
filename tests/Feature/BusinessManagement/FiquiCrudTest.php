<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Fiqui;
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

class FiquiCrudTest extends TestCase
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

        // Trafo mineral, 33 kV → clase de tensión "low".
        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'voltage_kv' => 33,
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->transformer->save();
    }

    public function test_create_diagnoses_and_updates_health_index(): void
    {
        // Todos óptimos (mineral low) → score 1 → Muy Bueno → HI 100.
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $sample = Fiqui::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertSame('Muy Bueno', $sample->condition);
        $this->assertEqualsWithDelta(1.0, (float) $sample->score, 0.01);

        $this->transformer->refresh();
        $this->assertEqualsWithDelta(100.0, (float) $this->transformer->health_index, 0.01);
    }

    public function test_update_and_delete_recompute(): void
    {
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
        ]);
        $sample = Fiqui::where('transformer_id', $this->transformer->id)->firstOrFail();

        // Todos pésimos → Muy Malo.
        $this->put(route('business_management.transformers.fiquis.update', [$this->transformer->slug, $sample->id]), [
            'sample_date' => '2024-09-04', 'rig' => 25, 'ten' => 10, 'acid' => 0.3, 'wat' => 50, 'pot' => 2,
        ])->assertRedirect();
        $this->assertSame('Muy Malo', $sample->fresh()->condition);

        $this->delete(route('business_management.transformers.fiquis.destroy', [$this->transformer->slug, $sample->id]))
            ->assertRedirect();
        $this->assertSoftDeleted('fiquis', ['id' => $sample->id]);
        $this->assertNull($this->transformer->fresh()->health_index);
    }

    public function test_date_required(): void
    {
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'rig' => 45,
        ])->assertSessionHasErrors('sample_date');
    }

    public function test_params_required_but_extras_optional(): void
    {
        // Solo fecha + rig: faltan ten/acid/wat, que sí son obligatorios (no
        // tienen método alterno y el ensayo siempre los mide).
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'rig' => 45,
        ])->assertSessionHasErrors(['ten', 'acid', 'wat']);

        // Con los 5 PARAMS y SIN los EXTRA (rig877/pot100) debe pasar.
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
        ])->assertSessionHasNoErrors();
    }

    /**
     * Rigidez y factor de potencia se miden por dos métodos y los cuatro campos
     * son opcionales: con uno alcanza (el motor sustituye) y con ninguno la
     * propiedad simplemente no participa. Obligar a llenarlos es lo que llevó al
     * sistema viejo a rellenar con ceros lo que el laboratorio no midió.
     */
    public function test_alternate_method_satisfies_the_pair(): void
    {
        // Solo el método alterno de cada par: válido.
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-05', 'ten' => 30, 'acid' => 0.03, 'wat' => 10,
            'rig877' => 40, 'pot100' => 0.3,
        ])->assertSessionHasNoErrors();

        // Y la muestra queda diagnosticada: la rigidez no se pierde.
        $s = $this->transformer->fiquis()->whereDate('sample_date', '2024-09-05')->first();
        $this->assertNotNull($s->score);

        // Sin ninguno de los dos métodos tampoco se reclama: 472 ensayos
        // históricos están así y editarlos no puede obligar a inventar un valor.
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-06', 'ten' => 30, 'acid' => 0.03, 'wat' => 10,
        ])->assertSessionHasNoErrors();
    }

    public function test_batch_creates_updates_and_deletes(): void
    {
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-09-04', 'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
        ]);
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-01-01', 'rig' => 44, 'ten' => 29, 'acid' => 0.04, 'wat' => 11, 'pot' => 0.06,
        ]);
        $samples = Fiqui::where('transformer_id', $this->transformer->id)->orderBy('id')->get();
        [$toEdit, $toDelete] = [$samples[0], $samples[1]];

        $this->post(route('business_management.transformers.fiquis.batch', $this->transformer->slug), [
            'upserts' => [
                ['id' => $toEdit->id, 'sample_date' => '2024-09-04', 'rig' => 25, 'ten' => 10, 'acid' => 0.3, 'wat' => 50, 'pot' => 2],
                ['id' => null, 'sample_date' => '2024-12-01', 'rig' => 46, 'ten' => 31, 'acid' => 0.02, 'wat' => 8, 'pot' => 0.04],
            ],
            'deletes' => [$toDelete->id],
        ])->assertRedirect(route('business_management.transformers.show', $this->transformer->slug));

        $this->assertSame('Muy Malo', $toEdit->fresh()->condition);
        $this->assertSoftDeleted('fiquis', ['id' => $toDelete->id]);
        $this->assertSame(2, Fiqui::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_explain_returns_trace_without_persisting(): void
    {
        $res = $this->postJson(route('business_management.transformers.fiquis.explain', $this->transformer->slug), [
            'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
            // Los dos métodos alternos SÍ medidos: solo así se listan en el
            // bloque de referencia (sin valor no aportan nada y se omiten).
            'rig877' => 50, 'pot100' => 2,
        ]);

        $res->assertOk();
        $res->assertJsonPath('fiquis.has_rules', true);
        $res->assertJsonPath('fiquis.condition', 'Muy Bueno');
        $res->assertJsonPath('fiquis.oil', 'Mineral');
        // La traza trae los 5 que PUNTÚAN, cada uno con su score…
        $params = collect($res->json('fiquis.params'));
        $puntuan = $params->where('scores', true);
        $this->assertCount(5, $puntuan);
        $this->assertSame(1, $res->json('fiquis.params.0.score'));

        // …más los de REFERENCIA MEDIDOS (rigidez D877, factor de potencia a
        // 100 °C): se listan con su límite pero NO puntúan ni suman peso, para
        // que no se cuente dos veces la misma propiedad.
        $referencia = $params->where('scores', false);
        $this->assertSame(['pot100', 'rig877'], $referencia->pluck('param')->sort()->values()->all());
        foreach ($referencia as $p) {
            $this->assertNull($p['weight'], "{$p['param']} no debe tener peso");
            $this->assertNull($p['score'], "{$p['param']} no debe puntuar");
            $this->assertNull($p['contribution'], "{$p['param']} no debe aportar al DGAF");
            $this->assertNotNull($p['limit'], "{$p['param']} debe traer su límite");
        }
        // La suma de pesos sigue siendo la de los 5 puntuables (rig 3 + ten 2 +
        // acid 1 + wat 4 + pot 3 = 13): los de referencia no la mueven.
        $this->assertSame(13.0, (float) $res->json('fiquis.sum_weight'));
        // El semáforo trae una sola banda marcada.
        $this->assertCount(1, collect($res->json('fiquis.semaphore'))->where('matched', true));
        // No persiste.
        $this->assertSame(0, Fiqui::where('transformer_id', $this->transformer->id)->count());
    }

    /**
     * Regresión: el show debe resolver el ESTADO de fiquis. El motor diagnostica
     * por oilType->code; si el eager-load del controlador no trae `code`, el estado
     * sale "—" (bug real). Este test pega a la ruta show real para protegerlo.
     */
    public function test_show_resolves_fiqui_state_via_oil_code(): void
    {
        Fiqui::create([
            'transformer_id' => $this->transformer->id, 'sample_date' => '2024-01-01',
            'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
            'tenant_id' => 1, 'created_by' => $this->transformer->created_by,
        ]);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        $this->get(route('business_management.transformers.show', $this->transformer->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('diagnosisSummary.fiquis.condition', 'Muy Bueno')
                ->where('fiquis.0.condition', 'Muy Bueno'));
    }

    /** Los campos extra (PF 100°C, Rigidez D877) se registran pero no puntúan. */
    public function test_batch_saves_extra_recorded_fields(): void
    {
        $this->post(route('business_management.transformers.fiquis.batch', $this->transformer->slug), [
            'upserts' => [[
                'sample_date' => '2024-02-01', 'rig' => 45, 'ten' => 30, 'acid' => 0.03,
                'wat' => 10, 'pot' => 0.05, 'rig877' => 28, 'pot100' => 0.42,
            ]],
        ])->assertRedirect();

        $s = Fiqui::where('transformer_id', $this->transformer->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(28, (float) $s->rig877, 0.001);
        $this->assertEqualsWithDelta(0.42, (float) $s->pot100, 0.001);
        // No alteran el estado: con los PARAMS óptimos sigue Muy Bueno.
        $this->assertSame('Muy Bueno', $s->condition);
    }

    public function test_pdf_report_downloads(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
        Fiqui::create([
            'transformer_id' => $this->transformer->id, 'sample_date' => '2024-01-01',
            'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
            'tenant_id' => 1, 'created_by' => $this->transformer->created_by,
        ]);

        // El PDF por prueba se eliminó: lo cubre el informe ÚNICO del trafo.
        $res = $this->get(route('business_management.transformers.report', $this->transformer->slug));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    /** Lo mismo por la grilla (batch), que es por donde entra en la práctica. */
    public function test_batch_accepts_only_the_alternate_method(): void
    {
        $this->post(route('business_management.transformers.fiquis.batch', $this->transformer->slug), [
            'upserts' => [[
                'sample_date' => '2024-10-01', 'ten' => 30, 'acid' => 0.03, 'wat' => 10,
                'rig877' => 40, 'pot100' => 0.3,
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(
            $this->transformer->fiquis()->whereDate('sample_date', '2024-10-01')->first()?->score,
        );
    }

    /**
     * Un ensayo histórico sin rigidez ni factor por ningún método (472 así en la
     * base real) tiene que poder editarse en cualquier otra columna sin que el
     * formulario obligue a inventar la medición que nunca se hizo.
     */
    public function test_editing_a_sample_without_either_method_is_not_blocked(): void
    {
        $this->post(route('business_management.transformers.fiquis.batch', $this->transformer->slug), [
            'upserts' => [['sample_date' => '2024-11-01', 'ten' => 30, 'acid' => 0.03, 'wat' => 10]],
        ])->assertSessionHasNoErrors();

        $s = $this->transformer->fiquis()->whereDate('sample_date', '2024-11-01')->first();
        $this->assertNull($s->rig);
        $this->assertNull($s->pot);

        // Se corrige solo el agua: sigue sin pedirse rigidez ni factor.
        $this->post(route('business_management.transformers.fiquis.batch', $this->transformer->slug), [
            'upserts' => [['id' => $s->id, 'sample_date' => '2024-11-01', 'ten' => 30, 'acid' => 0.03, 'wat' => 12]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(12.0, (float) $s->fresh()->wat);
    }

    /**
     * El 0 en rigidez o factor de potencia se rechaza: no es una medición, es el
     * "no medido" del sistema viejo. Una rigidez de 0 kV no existe y hacía que
     * el motor marcara fuera de norma a transformadores sanos. En acidez, agua y
     * tensión interfacial el 0 sí puede ser real y se acepta.
     */
    public function test_zero_is_rejected_only_where_it_cannot_be_a_measurement(): void
    {
        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-12-01', 'ten' => 30, 'acid' => 0.03, 'wat' => 10,
            'rig' => 0, 'pot' => 0, 'rig877' => 0, 'pot100' => 0,
        ])->assertSessionHasErrors(['rig', 'pot', 'rig877', 'pot100']);

        $this->post(route('business_management.transformers.fiquis.store', $this->transformer->slug), [
            'sample_date' => '2024-12-02', 'ten' => 0, 'acid' => 0, 'wat' => 0, 'rig' => 45,
        ])->assertSessionHasNoErrors();
    }
}
