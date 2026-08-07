<?php

namespace Tests\Feature\Api\V1;

use App\Models\Chromatographical;
use App\Models\Customer;
use App\Models\CustomerArea;
use App\Models\CustomerLocation;
use App\Models\CustomerSubstation;
use App\Models\Fiqui;
use App\Models\Fpot;
use App\Models\Furano;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\CromasRulesSeeder;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API del laboratorio (docs/API-LABORATORIO.md).
 *
 * Lo que se está protegiendo acá, en orden de importancia:
 *
 *   1. IDEMPOTENCIA — que un reintento NO duplique la muestra. Es el agujero
 *      que hoy tiene la integración y el que puede corromper una tendencia sin
 *      que nadie lo note.
 *   2. Que la API no adivine el transformador ni el tipo de equipo.
 *   3. Que el diagnóstico corra al ingresar (índice de salud + caché de flota).
 *   4. Que las abilities del token efectivamente cierren la puerta.
 */
class LabResultApiTest extends TestCase
{
    use RefreshDatabase;

    private Transformer $transformer;
    private Customer $customer;
    private CustomerSubstation $substation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedParentRows();
        $this->seed(DiagnosticCatalogSeeder::class);
        $this->seed(CromasRulesSeeder::class);

        $this->customer = Customer::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'name' => 'Minera Andina']);

        $location = CustomerLocation::create(['customer_id' => $this->customer->id, 'name' => 'Sede Lima', 'tenant_id' => 1]);
        $area     = CustomerArea::create(['customer_location_id' => $location->id, 'name' => 'Área 1', 'tenant_id' => 1]);
        $this->substation = CustomerSubstation::create(['customer_area_id' => $area->id, 'name' => 'SE Norte', 'tenant_id' => 1]);

        $this->transformer = $this->makeTransformer('SN-1000', 'TR-01');
    }

    protected function seedParentRows(): void
    {
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español',
            'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22), 'name' => '__bootstrap__', 'is_active' => false,
            'deleted_at' => now(), 'deleted_description' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Perú', 'iso_code' => 'PE',
            'currency' => 'PEN', 'timezone' => 'America/Lima', 'default_locale_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Workspace 1', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        // enterprise: /api/v1 está detrás de plan_feature:api_access.
        DB::table('subscriptions')->insertOrIgnore([[
            'id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD',
            'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /** Token del laboratorio: por defecto con las tres abilities de la integración. */
    private function actingAsLab(array $abilities = ['lab:write', 'transformers:read', 'transformers:write']): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    private function makeTransformer(string $serial, string $tag): Transformer
    {
        $t = new Transformer();
        $t->forceFill([
            'slug'                   => Str::random(22),
            'serial'                 => $serial,
            'tag'                    => $tag,
            'customer_id'            => $this->customer->id,
            'customer_substation_id' => $this->substation->id,
            'oil_type_id'            => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id'    => TransformerType::where('code', 'potencia')->value('id'),
            'voltage_kv'             => 220,
            'tenant_id'              => 1,
        ]);
        $t->save();

        return $t;
    }

    /**
     * Envío típico: un informe con los cuatro ensayos.
     *
     * `tests` se REEMPLAZA entero (no se fusiona elemento por elemento): un
     * caso que manda un solo ensayo quiere ese ensayo, no el primero de la
     * lista base con campos pisados.
     */
    private function payload(array $overrides = []): array
    {
        $tests = $overrides['tests'] ?? null;
        unset($overrides['tests']);

        $payload = array_replace_recursive([
            'transformer' => ['slug' => $this->transformer->slug],
            'lab' => [
                'laboratory_code' => 'TRLAB',
                'report_number'   => 'REP-LAB-2026-0001',
                'sampled_at'      => '2026-07-21T09:30:00-05:00',
            ],
            'tests' => [
                [
                    'kind'   => 'chromatography',
                    'values' => ['h2' => 100, 'ch4' => 100, 'c2h4' => 200, 'c2h6' => 50,
                                 'co' => 500, 'co2' => 10000, 'c2h2' => 10, 'o2' => 2000, 'n2' => 50000],
                ],
                [
                    'kind'    => 'physicochemical',
                    'values'  => ['acid' => 0.02, 'rig' => 55, 'ten' => 38, 'wat' => 8, 'pot' => 0.1],
                    'methods' => ['rig' => ['standard' => 'ASTM D1816', 'gap_mm' => 2.0],
                                  'pot' => ['standard' => 'ASTM D924', 'temp_c' => 25]],
                ],
                ['kind' => 'furanos',      'values' => ['fal' => 120]],
                ['kind' => 'power_factor', 'values' => ['pot' => 0.15, 'temp_c' => 25]],
            ],
        ], $overrides);

        if ($tests !== null) {
            $payload['tests'] = $tests;
        }

        return $payload;
    }

    private function postResults(array $payload, string $key = 'idem-0001')
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/lab-results', $payload);
    }

    // ─── AUTH / ABILITIES ──────────────────────────────────────────────────

    public function test_lab_results_requires_authentication(): void
    {
        $this->postJson('/api/v1/lab-results', [])->assertUnauthorized();
    }

    public function test_lab_results_requires_lab_write_ability(): void
    {
        $this->actingAsLab(['transformers:read']);
        $this->postResults($this->payload())->assertForbidden();
    }

    public function test_lookup_requires_transformers_read_ability(): void
    {
        $this->actingAsLab(['lab:write']);
        $this->getJson('/api/v1/transformers/lookup?serial=SN-1000')->assertForbidden();
    }

    public function test_store_transformer_requires_transformers_write_ability(): void
    {
        $this->actingAsLab(['lab:write', 'transformers:read']);
        $this->postJson('/api/v1/transformers', [])->assertForbidden();
    }

    // ─── BÚSQUEDA ──────────────────────────────────────────────────────────

    public function test_lookup_returns_candidates_with_slug(): void
    {
        $this->actingAsLab();

        $response = $this->getJson('/api/v1/transformers/lookup?serial=SN-1000')->assertOk();

        $response->assertJsonPath('data.0.slug', $this->transformer->slug);
        $response->assertJsonPath('data.0.customer.name', 'Minera Andina');
        $response->assertJsonPath('data.0.substation.name', 'SE Norte');
    }

    public function test_lookup_returns_every_candidate_without_choosing(): void
    {
        $this->actingAsLab();
        $this->makeTransformer('SN-1000', 'TR-02');   // misma serie, otro tag

        $this->getJson('/api/v1/transformers/lookup?serial=SN-1000')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_lookup_without_criteria_is_rejected(): void
    {
        $this->actingAsLab();

        $this->getJson('/api/v1/transformers/lookup')
            ->assertStatus(422)
            ->assertJsonPath('code', 'lookup_criteria_required');
    }

    // ─── ALTA DE TRANSFORMADOR ─────────────────────────────────────────────

    public function test_store_transformer_maps_type_voltage_and_phases(): void
    {
        $this->actingAsLab();

        $response = $this->postJson('/api/v1/transformers', [
            'serial'                 => 'SN-2000',
            'tag'                    => 'TR-99',
            'customer_id'            => $this->customer->id,
            'customer_substation_id' => $this->substation->id,
            'transformer_type'       => 'potencia',
            'oil_type'               => 'mineral',
            // Tres devanados: se guarda el mayor (define la clase de tensión).
            'voltage_kv_hv'          => 220,
            'voltage_kv_lv'          => 60,
            'voltage_kv_tv'          => 10,
            // Entero del laboratorio → texto nuestro.
            'phases'                 => 3,
        ])->assertCreated();

        $created = Transformer::where('serial', 'SN-2000')->firstOrFail();
        $this->assertSame('three', $created->phases);
        $this->assertEqualsWithDelta(220.0, (float) $created->voltage_kv, 0.01);
        $response->assertJsonPath('data.slug', $created->slug);
    }

    public function test_store_transformer_rejects_equipment_type_it_cannot_diagnose(): void
    {
        $this->actingAsLab();

        // Un buje no es un transformador: aceptarlo como "potencia" le aplicaría
        // un cuadro de reglas que no le corresponde.
        $this->postJson('/api/v1/transformers', [
            'serial' => 'SN-3000', 'tag' => 'BU-01',
            'customer_id' => $this->customer->id,
            'customer_substation_id' => $this->substation->id,
            'transformer_type' => 'bushing',
            'oil_type' => 'mineral',
            'voltage_kv' => 60,
        ])->assertStatus(422)->assertJsonValidationErrors('transformer_type');

        $this->assertDatabaseMissing('transformers', ['serial' => 'SN-3000']);
    }

    public function test_store_transformer_without_substation_answers_with_the_available_ones(): void
    {
        $this->actingAsLab();

        $this->postJson('/api/v1/transformers', [
            'serial' => 'SN-4000', 'tag' => 'TR-77',
            'customer_id' => $this->customer->id,
            'substation'  => 'SE Que No Existe',
            'transformer_type' => 'potencia',
            'oil_type' => 'mineral',
            'voltage_kv' => 60,
        ])->assertStatus(422)
          ->assertJsonPath('available_substations.0.name', 'SE Norte');
    }

    public function test_store_transformer_rejects_repeated_serial_and_tag(): void
    {
        $this->actingAsLab();

        $this->postJson('/api/v1/transformers', [
            'serial' => 'SN-1000', 'tag' => 'TR-01',
            'customer_id' => $this->customer->id,
            'customer_substation_id' => $this->substation->id,
            'transformer_type' => 'potencia',
            'oil_type' => 'mineral',
            'voltage_kv' => 220,
        ])->assertStatus(422)->assertJsonValidationErrors('tag');
    }

    // ─── INGESTA ───────────────────────────────────────────────────────────

    public function test_ingests_the_four_tests_and_runs_the_diagnosis(): void
    {
        $this->actingAsLab();

        $response = $this->postResults($this->payload())->assertCreated();

        $this->assertSame(1, Chromatographical::where('transformer_id', $this->transformer->id)->count());
        $this->assertSame(1, Fiqui::where('transformer_id', $this->transformer->id)->count());
        $this->assertSame(1, Furano::where('transformer_id', $this->transformer->id)->count());
        $this->assertSame(1, Fpot::where('transformer_id', $this->transformer->id)->count());

        // La cromatografía queda diagnosticada (DGAF cacheado en la fila).
        $croma = Chromatographical::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertSame('Muy Bueno', $croma->dgaf_condition);
        $this->assertSame('REP-LAB-2026-0001', $croma->report_number);
        $this->assertNotNull($croma->laboratory_id);

        // Y el transformador queda con índice de salud + caché de flota al día:
        // esto es lo que NO pasaba cuando el laboratorio escribía por SQL.
        $this->transformer->refresh();
        $this->assertNotNull($this->transformer->health_index);
        $this->assertNotNull($this->transformer->health_rating);
        $this->assertNotNull($this->transformer->ieee_condition);

        $response->assertJsonPath('transformer.slug', $this->transformer->slug);
        $response->assertJsonCount(4, 'created');
    }

    public function test_ingests_by_serial_and_tag_when_there_is_no_slug(): void
    {
        $this->actingAsLab();

        $this->postResults($this->payload([
            'transformer' => ['slug' => null, 'serial' => 'SN-1000', 'tag' => 'TR-01'],
        ]))->assertCreated();

        $this->assertSame(1, Chromatographical::where('transformer_id', $this->transformer->id)->count());
    }

    public function test_does_not_guess_when_more_than_one_transformer_matches(): void
    {
        $this->actingAsLab();
        $this->makeTransformer('SN-1000', 'TR-02');

        // Serie repetida y sin tag: son dos equipos distintos. El sistema viejo
        // se quedaba con el primero y mandaba la muestra al equipo equivocado.
        $this->postResults($this->payload([
            'transformer' => ['slug' => null, 'serial' => 'SN-1000'],
        ]))->assertStatus(422)->assertJsonValidationErrors('transformer');

        $this->assertSame(0, Chromatographical::count());
    }

    public function test_rejects_unknown_analyte_instead_of_dropping_it(): void
    {
        $this->actingAsLab();

        $this->postResults($this->payload([
            'tests' => [['kind' => 'chromatography', 'values' => ['h2' => 10, 'plutonio' => 3]]],
        ]))->assertStatus(422);

        $this->assertSame(0, Chromatographical::count());
    }

    public function test_routes_the_alternate_method_to_its_own_column(): void
    {
        $this->actingAsLab();

        // Rigidez medida con D877: va a `rig877`, NO a `rig`. Los kV de una
        // norma y de la otra no son comparables.
        $this->postResults($this->payload([
            'tests' => [[
                'kind'    => 'physicochemical',
                'values'  => ['acid' => 0.02, 'rig' => 30, 'ten' => 38, 'wat' => 8],
                'methods' => ['rig' => ['standard' => 'ASTM D877']],
            ]],
        ]))->assertCreated();

        $fiqui = Fiqui::where('transformer_id', $this->transformer->id)->firstOrFail();
        $this->assertNull($fiqui->rig);
        $this->assertEqualsWithDelta(30.0, (float) $fiqui->rig877, 0.001);
        // Y queda constancia de con qué se midió.
        $this->assertSame('ASTM D877', $fiqui->methods['rig']['standard'] ?? null);
    }

    public function test_rejects_a_second_sample_on_the_same_date_for_the_same_test(): void
    {
        $this->actingAsLab();

        $this->postResults($this->payload([
            'tests' => [['kind' => 'furanos', 'values' => ['fal' => 120]]],
        ]), 'idem-a')->assertCreated();

        // Otra clave (envío distinto) pero la misma fecha de muestra: es la
        // misma regla que aplica el formulario web.
        $this->postResults($this->payload([
            'tests' => [['kind' => 'furanos', 'values' => ['fal' => 300]]],
        ]), 'idem-b')->assertStatus(422);

        $this->assertSame(1, Furano::count());
    }

    // ─── IDEMPOTENCIA ──────────────────────────────────────────────────────

    public function test_requires_the_idempotency_key_header(): void
    {
        $this->actingAsLab();

        $this->postJson('/api/v1/lab-results', $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('code', 'idempotency_key_required');

        $this->assertSame(0, Chromatographical::count());
    }

    public function test_a_retry_returns_the_same_response_and_does_not_duplicate(): void
    {
        $this->actingAsLab();
        $payload = $this->payload();

        $first = $this->postResults($payload, 'idem-retry')->assertCreated();

        // El reintento del laboratorio tras un timeout: mismo cuerpo, misma clave.
        $second = $this->postResults($payload, 'idem-retry')
            ->assertOk()                       // 200, no 201: no se creó nada nuevo
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame($first->json(), $second->json());

        $this->assertSame(1, Chromatographical::count());
        $this->assertSame(1, Fiqui::count());
        $this->assertSame(1, Furano::count());
        $this->assertSame(1, Fpot::count());
    }

    public function test_the_same_key_with_a_different_body_is_a_conflict(): void
    {
        $this->actingAsLab();

        $this->postResults($this->payload(), 'idem-reused')->assertCreated();

        $other = $this->payload(['tests' => [['kind' => 'furanos', 'values' => ['fal' => 999]]]]);
        $this->postResults($other, 'idem-reused')
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_key_reused');

        $this->assertSame(1, Furano::count());
    }

    public function test_a_failed_request_releases_its_key_so_it_can_be_corrected(): void
    {
        $this->actingAsLab();

        // Envío inválido: no se guarda nada y la clave queda libre.
        $this->postResults($this->payload([
            'tests' => [['kind' => 'plasma', 'values' => ['x' => 1]]],
        ]), 'idem-fix')->assertStatus(422);

        $this->assertDatabaseCount('idempotency_keys', 0);

        // El laboratorio corrige y reintenta con la MISMA clave.
        $this->postResults($this->payload(), 'idem-fix')->assertCreated();
        $this->assertSame(1, Chromatographical::count());
    }

    public function test_the_transaction_leaves_nothing_behind_when_one_test_fails(): void
    {
        $this->actingAsLab();

        // La cromatografía es válida, el segundo ensayo no. No debe quedar ni la
        // primera: es UN informe, entra entero o no entra.
        $this->postResults($this->payload([
            'tests' => [
                ['kind' => 'chromatography', 'values' => ['h2' => 100, 'ch4' => 100]],
                ['kind' => 'furanos',        'values' => ['hme' => 5]],   // sin fal
            ],
        ]))->assertStatus(422);

        $this->assertSame(0, Chromatographical::count());
        $this->assertSame(0, Furano::count());
    }
}
