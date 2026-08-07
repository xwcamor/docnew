<?php

namespace Tests\Feature\Diagnostics;

use App\Models\Chromatographical;
use App\Models\Customer;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use App\Services\Diagnostics\IeeeDgaStatusService;
use App\Services\Reports\TransformerReportService;
use App\Support\Transformers\TransformerShowPayload;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cobertura del método DGA Status (IEEE C57.104-2019) de punta a punta:
 *  - el servicio clasifica el estado y respeta la selección manual de Tabla 4
 *  - el payload del trafo expone cromasDgaStatus
 *  - el informe PDF renderiza la sección sin romper
 */
class IeeeDgaReportTest extends TestCase
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
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($u);

        $customer = (new Customer())->forceFill(['slug' => 'c-' . uniqid(), 'name' => 'Cliente X', 'tenant_id' => 1, 'created_by' => $u->id]);
        $customer->save();

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR', 'customer_id' => $customer->id,
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'manufacture_year' => now()->year - 5,
            'tenant_id' => 1, 'created_by' => $u->id,
        ]);
        $this->transformer->save();
    }

    /** Crea N muestras de cromas espaciadas en el último año, con gases en alza. */
    private function seedRisingSamples(): void
    {
        $base = now()->subMonths(12);
        $rows = [
            ['m' => 0,  'h2' => 10, 'ch4' => 5,  'c2h4' => 4,  'c2h2' => 0],
            ['m' => 4,  'h2' => 25, 'ch4' => 12, 'c2h4' => 10, 'c2h2' => 0],
            ['m' => 8,  'h2' => 60, 'ch4' => 30, 'c2h4' => 25, 'c2h2' => 1],
            ['m' => 12, 'h2' => 120,'ch4' => 70, 'c2h4' => 55, 'c2h2' => 3],
        ];
        foreach ($rows as $r) {
            Chromatographical::create([
                'transformer_id' => $this->transformer->id,
                'sample_date' => (clone $base)->addMonths($r['m']),
                'h2' => $r['h2'], 'ch4' => $r['ch4'], 'c2h4' => $r['c2h4'], 'c2h2' => $r['c2h2'],
                'co' => 100, 'co2' => 1000, 'c2h6' => 2, 'o2' => 2000, 'n2' => 50000,
            ]);
        }
    }

    public function test_service_flags_status_3_for_rising_gases(): void
    {
        $this->seedRisingSamples();
        $samples = $this->transformer->chromatographicals()->orderBy('sample_date')->get();

        $res = app(IeeeDgaStatusService::class)->evaluate($samples, 5, null);

        $this->assertNotNull($res);
        $this->assertSame('auto', $res['mode']);
        $this->assertSame(3, $res['status']);              // gases en alza fuerte → Estado 3
        $this->assertTrue($res['table4_applicable']);
    }

    public function test_manual_selection_is_respected(): void
    {
        $this->seedRisingSamples();
        $ids = $this->transformer->chromatographicals()->orderBy('sample_date')->pluck('id')->all();
        $manual = array_slice($ids, 0, 3);

        $samples = $this->transformer->chromatographicals()->orderBy('sample_date')->get();
        $res = app(IeeeDgaStatusService::class)->evaluate($samples, 5, $manual);

        $this->assertSame('manual', $res['mode']);
        // El motor devuelve las muestras ordenadas por fecha; comparar sin orden.
        $this->assertEqualsCanonicalizing($manual, $res['rate_sample_ids']);
        $this->assertCount(3, $res['rate_sample_ids']);
    }

    public function test_payload_exposes_dga_status(): void
    {
        $this->seedRisingSamples();
        $payload = app(TransformerShowPayload::class)->build($this->transformer->fresh());

        $this->assertArrayHasKey('cromasDgaStatus', $payload);
        $this->assertNotNull($payload['cromasDgaStatus']);
        $this->assertArrayHasKey('rate', $payload['cromasDgaStatus']);
    }

    public function test_report_pdf_renders_with_dga_section(): void
    {
        $this->seedRisingSamples();

        $pdf = app(TransformerReportService::class)->pdf($this->transformer->fresh(), [], auth()->user());
        $bytes = $pdf->output();   // fuerza el render del blade (incl. sección DGA)

        $this->assertStringStartsWith('%PDF', $bytes);
    }
}
