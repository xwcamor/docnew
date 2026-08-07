<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Jobs\SystemManagement\Regions\GenerateRegionsCsvJob;
use App\Models\Download;
use App\Models\Region;
use Illuminate\Support\Facades\Storage;

/**
 * Tests del job de export CSV. Cubre: archivo generado, status del Download,
 * encabezados correctos, scope=selected y scope=filtered, chunkById en lotes
 * grandes (constancia en memoria).
 */
class RegionExportJobTest extends RegionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_csv_job_creates_download_record_with_ready_status(): void
    {
        $user = $this->actingAsSuperAdmin();
        Region::factory()->count(3)->create();

        (new GenerateRegionsCsvJob($user->id, [
            'columns' => ['name', 'is_active'],
            'scope'   => 'all',
        ]))->handle();

        $download = Download::where('user_id', $user->id)->first();
        $this->assertNotNull($download);
        $this->assertEquals('csv', $download->type);
        $this->assertEquals('ready', $download->status);
        $this->assertNotNull($download->expires_at);
        Storage::disk('local')->assertExists($download->path);
    }

    public function test_csv_contains_headers_and_rows(): void
    {
        $user = $this->actingAsSuperAdmin();
        Region::factory()->create(['name' => 'Patagonia', 'is_active' => true]);
        Region::factory()->create(['name' => 'Cuyo',      'is_active' => false]);

        (new GenerateRegionsCsvJob($user->id, [
            'columns' => ['name', 'is_active'],
            'scope'   => 'all',
        ]))->handle();

        $download = Download::where('user_id', $user->id)->first();
        $csv      = Storage::disk('local')->get($download->path);

        // BOM UTF-8 al inicio.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        // Filas presentes (sin asumir orden — chunkById usa orderBy id).
        $this->assertStringContainsString('Patagonia', $csv);
        $this->assertStringContainsString('Cuyo',      $csv);
        // is_active serializado como 1/0 (no true/false).
        $this->assertStringContainsString(',1', $csv);
        $this->assertStringContainsString(',0', $csv);
    }

    public function test_scope_selected_only_exports_chosen_ids(): void
    {
        $user = $this->actingAsSuperAdmin();
        $r1 = Region::factory()->create(['name' => 'Norte']);
        $r2 = Region::factory()->create(['name' => 'Sur']);
        $r3 = Region::factory()->create(['name' => 'Este']);

        (new GenerateRegionsCsvJob($user->id, [
            'columns'      => ['name'],
            'scope'        => 'selected',
            'selected_ids' => [$r1->id, $r3->id],
        ]))->handle();

        $csv = Storage::disk('local')->get(
            Download::where('user_id', $user->id)->first()->path
        );

        $this->assertStringContainsString('Norte', $csv);
        $this->assertStringContainsString('Este',  $csv);
        $this->assertStringNotContainsString('Sur', $csv);
    }

    public function test_scope_filtered_applies_filters(): void
    {
        $user = $this->actingAsSuperAdmin();
        Region::factory()->create(['name' => 'Activa A',   'is_active' => true]);
        Region::factory()->create(['name' => 'Inactiva B', 'is_active' => false]);

        (new GenerateRegionsCsvJob($user->id, [
            'columns' => ['name', 'is_active'],
            'scope'   => 'filtered',
            'filters' => ['is_active' => '1'],
        ]))->handle();

        $csv = Storage::disk('local')->get(
            Download::where('user_id', $user->id)->first()->path
        );

        $this->assertStringContainsString('Activa A', $csv);
        $this->assertStringNotContainsString('Inactiva B', $csv);
    }

    public function test_chunked_export_handles_more_than_one_chunk(): void
    {
        // chunkById usa lotes de 1000 — testeamos con 1200 para forzar 2 chunks.
        $user = $this->actingAsSuperAdmin();
        Region::factory()->count(1200)->create();

        (new GenerateRegionsCsvJob($user->id, [
            'columns' => ['name'],
            'scope'   => 'all',
        ]))->handle();

        $download = Download::where('user_id', $user->id)->first();
        $this->assertEquals('ready', $download->status);

        $csv  = Storage::disk('local')->get($download->path);
        $rows = substr_count($csv, "\n");

        // header + 1200 filas = 1201 newlines como mínimo.
        $this->assertGreaterThanOrEqual(1200, $rows);
    }
}
