<?php

namespace Tests\Feature\BusinessManagement;

use App\Jobs\BusinessManagement\Transformers\GenerateFleetReportExcelJob;
use App\Models\Chromatographical;
use App\Models\Fiqui;
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
use Tests\TestCase;

/**
 * Reporte de flota en Excel: las pestañas que arma el job.
 *
 * El caso que motivó estos tests: el job seleccionaba solo las columnas del
 * resumen, así que `oil_type_id` no llegaba al TransformerShowPayload, el
 * payload no resolvía el aceite y TODAS las muestras del libro salían sin
 * diagnóstico ("Sin reglas" en cromatografía, "—" en fisicoquímico). Los datos
 * crudos se veían bien y la columna del estado no, que es lo peor: parece un
 * libro completo.
 */
class FleetReportExcelTest extends TestCase
{
    use RefreshDatabase;

    private Transformer $transformer;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);
        $this->seed(CromasRulesSeeder::class);

        $this->user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        $this->transformer = (new Transformer())->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S1', 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'voltage_kv' => 33, 'tenant_id' => 1, 'created_by' => $this->user->id,
        ]);
        $this->transformer->save();

        $comun = ['transformer_id' => $this->transformer->id, 'tenant_id' => 1, 'created_by' => $this->user->id];

        (new Fiqui())->forceFill($comun + [
            'sample_date' => '2024-05-10',
            'rig' => 45, 'ten' => 30, 'acid' => 0.03, 'wat' => 10, 'pot' => 0.05,
        ])->save();

        (new Chromatographical())->forceFill($comun + [
            'sample_date' => '2024-05-10',
            'h2' => 10, 'ch4' => 5, 'c2h6' => 4, 'c2h4' => 3, 'c2h2' => 0.5, 'co' => 200, 'co2' => 1500,
        ])->save();

        (new Furano())->forceFill($comun + ['sample_date' => '2024-05-10', 'fal' => 120])->save();
    }

    /** @return array<int,array{title:string,headings:array,rows:array}> */
    private function hojas(): array
    {
        $job = new GenerateFleetReportExcelJob($this->user->id, null, [$this->transformer->id]);
        $m = (new \ReflectionClass($job))->getMethod('buildSheets');
        $m->setAccessible(true);

        return $m->invoke($job);
    }

    private function hoja(array $hojas, int $i): array
    {
        return $hojas[$i];
    }

    /**
     * El estado de cada muestra tiene que salir diagnosticado. Si el aceite no
     * llega al payload, todas las filas caen a "Sin reglas" / "—" y el libro
     * parece completo aunque su columna más importante esté vacía.
     */
    public function test_every_sample_carries_its_diagnosed_condition(): void
    {
        $hojas = $this->hojas();

        foreach ([1 => 'cromatografía', 2 => 'furanos', 3 => 'fisicoquímico'] as $i => $nombre) {
            $filas = $this->hoja($hojas, $i)['rows'];
            $this->assertNotEmpty($filas, "la pestaña de $nombre quedó vacía");

            $estado = end($filas[0]);   // la condición es la última columna
            $this->assertNotSame('—', $estado, "$nombre sin condición");
            $this->assertNotSame('Sin reglas', $estado, "$nombre no resolvió el aceite");
        }
    }

    /**
     * DGAF es el promedio ponderado interno del motor y su columna en la base
     * está vacía en todas las filas: no va al libro. La condición ya dice a
     * dónde llegó ese número.
     */
    public function test_cromas_sheet_has_no_dgaf_column(): void
    {
        $cabeceras = $this->hoja($this->hojas(), 1)['headings'];

        foreach ($cabeceras as $h) {
            $this->assertStringNotContainsStringIgnoringCase('dgaf', (string) $h);
        }
        // Serie + fecha + 9 gases + condición.
        $this->assertCount(12, $cabeceras);
    }

    /** El DP sale del diagnóstico; la columna `dp` de la tabla está vacía. */
    public function test_furanos_sheet_fills_the_paper_dp(): void
    {
        $fila = $this->hoja($this->hojas(), 2)['rows'][0];

        $this->assertNull(Furano::first()->dp, 'la columna de la BD sigue vacía, como se esperaba');
        $this->assertNotSame('—', $fila[7]);
        $this->assertGreaterThan(0, (float) $fila[7]);
    }

    /**
     * En una hoja suelta no hay tooltip ni columna vecina: los dos métodos de
     * rigidez y los dos de factor de potencia tienen que distinguirse por su
     * cabecera, con la norma y la unidad.
     */
    public function test_headings_identify_each_method_with_its_standard_and_unit(): void
    {
        $hojas = $this->hojas();

        $gases = $this->hoja($hojas, 1)['headings'][2];
        $this->assertStringContainsString('H₂', $gases);
        $this->assertStringContainsString('ppm', $gases);

        $fiquis = $this->hoja($hojas, 3)['headings'];
        $this->assertStringContainsString('2.0 mm', $fiquis[2]);
        $this->assertStringContainsString('D1816', $fiquis[2]);
        $this->assertStringContainsString('2.54 mm', $fiquis[7]);
        $this->assertStringContainsString('D877', $fiquis[7]);
        $this->assertStringContainsString('25 °C', $fiquis[6]);
        $this->assertStringContainsString('100 °C', $fiquis[8]);

        // Ninguna cabecera puede repetirse: era el síntoma visible.
        $this->assertSame(count($fiquis), count(array_unique($fiquis)));
    }

    /** Tendencia y tipo de falla son enums internos: no salen crudos en inglés. */
    public function test_summary_translates_trend_and_fault_type(): void
    {
        $this->transformer->forceFill(['health_trend' => 'worsening', 'fault_type' => 'thermal'])->save();

        $fila = $this->hoja($this->hojas(), 0)['rows'][0];

        $this->assertNotSame('worsening', $fila[9]);
        $this->assertNotSame('thermal', $fila[10]);
        $this->assertSame(__('transformers.fleet.trend_worsening'), $fila[9]);
        $this->assertSame(__('dashboard.fleet.fault_thermal'), $fila[10]);
    }
}
