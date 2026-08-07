<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Imports\SystemManagement\Regions\RegionsImport;
use App\Models\Region;
use Illuminate\Support\Collection;

/**
 * Tests del flujo de import — alimentamos `collection()` con filas crudas
 * (tipo Maatwebsite\Excel\Concerns\ToCollection) sin tocar archivos físicos.
 * Cubre: dedup in-file, validación, modos create_only/update_or_create,
 * dry-run, normalización de booleanos, transactional rollback.
 */
class RegionImportTest extends RegionTestCase
{
    /** Helper: corre el import con filas raw. */
    private function runImport(array $rows, string $mode = 'update_or_create', bool $dryRun = false): RegionsImport
    {
        $this->actingAsSuperAdmin();
        $import = new RegionsImport(mode: $mode, dryRun: $dryRun);
        $import->collection(new Collection($rows));
        return $import;
    }

    public function test_creates_new_rows_on_happy_path(): void
    {
        $import = $this->runImport([
            ['name' => 'Norte', 'is_active' => '1'],
            ['name' => 'Sur',   'is_active' => '0'],
        ]);

        $this->assertEquals(2, $import->created);
        $this->assertEquals(0, $import->updated);
        $this->assertEquals(0, $import->skipped);
        $this->assertEmpty($import->errors);

        $this->assertDatabaseHas('regions', ['name' => 'Norte', 'is_active' => true]);
        $this->assertDatabaseHas('regions', ['name' => 'Sur',   'is_active' => false]);
    }

    public function test_rejects_missing_or_empty_name(): void
    {
        $import = $this->runImport([
            ['name' => '',   'is_active' => '1'],
            ['name' => null, 'is_active' => '1'],
            ['name' => '   ', 'is_active' => '1'],
        ]);

        $this->assertEquals(0, $import->created);
        $this->assertCount(3, $import->errors);
    }

    public function test_rejects_name_too_long(): void
    {
        $import = $this->runImport([
            ['name' => str_repeat('x', 256), 'is_active' => '1'],
        ]);

        $this->assertEquals(0, $import->created);
        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('xxx', $import->errors[0]['value'] ?? '');
    }

    public function test_detects_in_file_duplicates_case_insensitive(): void
    {
        $import = $this->runImport([
            ['name' => 'Patagonia',  'is_active' => '1'],
            ['name' => 'PATAGONIA',  'is_active' => '1'],
            ['name' => 'patagonia',  'is_active' => '0'],
        ]);

        // Solo la primera entra; las otras 2 son duplicados in-file.
        $this->assertEquals(1, $import->created);
        $this->assertCount(2, $import->errors);
    }

    public function test_create_only_mode_skips_existing(): void
    {
        Region::factory()->create(['name' => 'Existente', 'is_active' => true]);

        $import = $this->runImport([
            ['name' => 'Existente', 'is_active' => '0'],
            ['name' => 'Nuevo',     'is_active' => '1'],
        ], mode: 'create_only');

        $this->assertEquals(1, $import->created);
        $this->assertEquals(1, $import->skipped);
        $this->assertEquals(0, $import->updated);

        // El existente NO debe haberse modificado (sigue is_active=true).
        $this->assertDatabaseHas('regions', ['name' => 'Existente', 'is_active' => true]);
    }

    public function test_update_or_create_mode_updates_existing(): void
    {
        Region::factory()->create(['name' => 'Cuyo', 'is_active' => true]);

        $import = $this->runImport([
            ['name' => 'Cuyo', 'is_active' => '0'],
        ], mode: 'update_or_create');

        $this->assertEquals(0, $import->created);
        $this->assertEquals(1, $import->updated);

        $this->assertDatabaseHas('regions', ['name' => 'Cuyo', 'is_active' => false]);
    }

    public function test_dry_run_rolls_back_all_changes(): void
    {
        $before = Region::count();

        $import = $this->runImport([
            ['name' => 'Temporal', 'is_active' => '1'],
        ], dryRun: true);

        // Counters reflejan lo que HUBIERA pasado.
        $this->assertEquals(1, $import->created);
        // Pero DB no fue tocada.
        $this->assertEquals($before, Region::count());
        $this->assertDatabaseMissing('regions', ['name' => 'Temporal']);
    }

    public function test_normalizes_boolean_in_many_variants(): void
    {
        $import = $this->runImport([
            ['name' => 'A', 'is_active' => 'yes'],
            ['name' => 'B', 'is_active' => 'no'],
            ['name' => 'C', 'is_active' => 'sí'],
            ['name' => 'D', 'is_active' => 'inactivo'],
            ['name' => 'E', 'is_active' => 'true'],
            ['name' => 'F', 'is_active' => 0],
            ['name' => 'G', 'is_active' => null], // default true
        ]);

        $this->assertEquals(7, $import->created);
        $this->assertDatabaseHas('regions', ['name' => 'A', 'is_active' => true]);
        $this->assertDatabaseHas('regions', ['name' => 'B', 'is_active' => false]);
        $this->assertDatabaseHas('regions', ['name' => 'C', 'is_active' => true]);
        $this->assertDatabaseHas('regions', ['name' => 'D', 'is_active' => false]);
        $this->assertDatabaseHas('regions', ['name' => 'E', 'is_active' => true]);
        $this->assertDatabaseHas('regions', ['name' => 'F', 'is_active' => false]);
        $this->assertDatabaseHas('regions', ['name' => 'G', 'is_active' => true]);
    }

    public function test_summary_contains_expected_keys(): void
    {
        $import = $this->runImport([
            ['name' => 'A', 'is_active' => '1'],
            ['name' => '',  'is_active' => '1'],
        ]);

        $summary = $import->summary();
        $this->assertArrayHasKey('created',     $summary);
        $this->assertArrayHasKey('updated',     $summary);
        $this->assertArrayHasKey('skipped',     $summary);
        $this->assertArrayHasKey('error_count', $summary);
        $this->assertArrayHasKey('total_rows',  $summary);
        $this->assertArrayHasKey('preview',     $summary);
        $this->assertEquals(1, $summary['created']);
        $this->assertEquals(1, $summary['error_count']);
        $this->assertEquals(2, $summary['total_rows']);
    }
}
