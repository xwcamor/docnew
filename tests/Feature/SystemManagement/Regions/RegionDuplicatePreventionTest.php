<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Models\Region;
use Illuminate\Http\UploadedFile;

/**
 * Locks down the 3-layer duplicate prevention for Regions:
 *
 *   1. Form submission (StoreRequest / UpdateRequest via UniqueNormalizedName Rule)
 *   2. Excel/CSV import (RegionsImport: in-file dedup + DB lookup)
 *   3. DB-level partial unique index (final safety net)
 *
 * If you clone Regions to build a new module, copy this file and rename.
 */
class RegionDuplicatePreventionTest extends RegionTestCase
{
    // ─── FORM-LEVEL ─────────────────────────────────────────────────────────

    public function test_create_blocks_exact_name_duplicate(): void
    {
        $this->actingAsSuperAdmin();
        Region::factory()->named('América del Sur')->create();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => 'América del Sur',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Region::count());
    }

    public function test_create_blocks_case_insensitive_duplicate(): void
    {
        // Use ASCII-only variants so the test passes on both Postgres (full
        // Unicode case folding) and SQLite (LOWER only folds ASCII by default).
        $this->actingAsSuperAdmin();
        Region::factory()->named('Europa')->create();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => 'EUROPA',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Region::count());
    }

    public function test_create_blocks_accent_insensitive_duplicate(): void
    {
        // PostgreSQL only — SQLite has no unaccent. Skip on sqlite where the
        // accent-insensitive layer is best-effort via Rule fallback.
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Accent-insensitive matching requires Postgres unaccent extension.');
        }

        $this->actingAsSuperAdmin();
        Region::factory()->named('América del Sur')->create();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => 'America del Sur',  // sin tilde
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Region::count());
    }

    public function test_create_trims_whitespace_for_duplicate_check(): void
    {
        $this->actingAsSuperAdmin();
        Region::factory()->named('Europa')->create();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => '  Europa  ',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_create_allows_reuse_of_soft_deleted_name(): void
    {
        // Soft-delete-aware uniqueness requires the partial unique index,
        // which the migration only creates on Postgres. On SQLite the fallback
        // is a plain UNIQUE that blocks re-use even after soft-delete.
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Soft-delete-aware uniqueness requires Postgres partial index.');
        }

        $this->actingAsSuperAdmin();
        Region::factory()->named('Antártida')->trashed()->create();

        $response = $this->post(route('system_management.regions.store'), [
            'name' => 'Antártida',
        ]);

        $response->assertSessionHasNoErrors();
        // 1 active + 1 soft-deleted (factory state)
        $this->assertSame(1, Region::count());
        $this->assertSame(2, Region::withTrashed()->count());
    }

    public function test_update_can_save_same_name_for_same_record(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->named('Europa')->create();

        $response = $this->put(
            route('system_management.regions.update', $region->slug),
            ['name' => 'Europa', 'is_active' => true],
        );

        $response->assertSessionHasNoErrors();
    }

    public function test_update_blocks_duplicate_against_other_record(): void
    {
        $this->actingAsSuperAdmin();
        Region::factory()->named('Europa')->create();
        $other = Region::factory()->named('Asia')->create();

        $response = $this->put(
            route('system_management.regions.update', $other->slug),
            ['name' => 'Europa', 'is_active' => true],
        );

        $response->assertSessionHasErrors('name');
    }

    // ─── IMPORT-LEVEL ───────────────────────────────────────────────────────

    public function test_import_dry_run_flags_in_file_duplicates(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'is_active'],
            ['América del Sur', '1'],
            ['América del Sur', '1'],   // duplicate of row 2
            ['Europa',          '1'],
        ]);

        $response = $this->post(
            route('system_management.regions.import'),
            ['file' => $csv, 'mode' => 'create_only', 'dry_run' => 1],
        );

        $response->assertOk();
        $summary = $response->json('summary');

        $this->assertSame(1, $summary['error_count'], 'Expected one in-file duplicate to be flagged.');
        // Mensaje localizado — solo verificamos los datos variables (la fila
        // donde estaba el duplicado y la fila original referenciada en el
        // mensaje), no el copy en sí.
        $this->assertSame(3, $summary['errors'][0]['row']);  // dup found on row 3
        $this->assertStringContainsString('2', $summary['errors'][0]['message']);  // refs row 2 (original)
        // dry_run should have rolled back — DB should still be empty.
        $this->assertSame(0, Region::count());
    }

    public function test_import_commit_skips_existing_in_create_only_mode(): void
    {
        $this->actingAsSuperAdmin();
        Region::factory()->named('Europa')->create();

        $csv = $this->csv([
            ['name', 'is_active'],
            ['Europa', '1'],   // already exists → skip
            ['Asia',   '1'],   // new → create
        ]);

        $response = $this->post(
            route('system_management.regions.import'),
            ['file' => $csv, 'mode' => 'create_only', 'dry_run' => 0],
        );

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(2, Region::count());
    }

    public function test_import_commit_updates_existing_in_update_or_create_mode(): void
    {
        $this->actingAsSuperAdmin();
        $europa = Region::factory()->named('Europa')->create(['is_active' => false]);

        $csv = $this->csv([
            ['name', 'is_active'],
            ['Europa', '1'],   // exists → update is_active to true
        ]);

        $response = $this->post(
            route('system_management.regions.import'),
            ['file' => $csv, 'mode' => 'update_or_create', 'dry_run' => 0],
        );

        $response->assertOk();
        $this->assertTrue($europa->fresh()->is_active);
    }

    public function test_import_dry_run_does_not_persist_changes(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'is_active'],
            ['Europa', '1'],
            ['Asia',   '1'],
        ]);

        $this->post(
            route('system_management.regions.import'),
            ['file' => $csv, 'mode' => 'update_or_create', 'dry_run' => 1],
        );

        $this->assertSame(0, Region::count(), 'Dry-run must not persist any rows.');
    }

    public function test_import_rejects_rows_with_empty_name(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'is_active'],
            ['',     '1'],          // empty
            ['Asia', '1'],
        ]);

        $response = $this->post(
            route('system_management.regions.import'),
            ['file' => $csv, 'mode' => 'create_only', 'dry_run' => 0],
        );

        $summary = $response->json('summary');
        $this->assertSame(1, $summary['error_count']);
        $this->assertSame(1, $summary['created']);
    }

    // ─── HELPERS ────────────────────────────────────────────────────────────

    /** Build an in-memory CSV upload from a 2D array. */
    protected function csv(array $rows): UploadedFile
    {
        $content = '';
        foreach ($rows as $row) {
            $content .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', $v) . '"',
                $row,
            )) . "\n";
        }
        $tmp = tempnam(sys_get_temp_dir(), 'regimport') . '.csv';
        file_put_contents($tmp, $content);
        return new UploadedFile($tmp, 'regions.csv', 'text/csv', null, true);
    }
}
