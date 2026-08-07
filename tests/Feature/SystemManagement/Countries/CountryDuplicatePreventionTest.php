<?php

namespace Tests\Feature\SystemManagement\Countries;

use App\Models\Country;
use Illuminate\Http\UploadedFile;

/**
 * Locks down the 3-layer duplicate prevention for Countries:
 *
 *   1. Form submission (StoreRequest / UpdateRequest via UniqueNormalizedName Rule)
 *   2. Excel/CSV import (CountriesImport: in-file dedup + DB lookup)
 *   3. DB-level partial unique index (final safety net)
 *
 * If you clone Countries to build a new module, copy this file and rename.
 */
class CountryDuplicatePreventionTest extends CountryTestCase
{
    // ─── FORM-LEVEL ─────────────────────────────────────────────────────────

    public function test_create_blocks_exact_name_duplicate(): void
    {
        $this->actingAsSuperAdmin();
        Country::factory()->named('América del Sur')->create();

        $response = $this->post(route('system_management.countries.store'), $this->validCountryData([
            'name' => 'América del Sur',
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Country::count());
    }

    public function test_create_blocks_case_insensitive_duplicate(): void
    {
        // Use ASCII-only variants so the test passes on both Postgres (full
        // Unicode case folding) and SQLite (LOWER only folds ASCII by default).
        $this->actingAsSuperAdmin();
        Country::factory()->named('Europa')->create();

        $response = $this->post(route('system_management.countries.store'), $this->validCountryData([
            'name' => 'EUROPA',
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Country::count());
    }

    public function test_create_blocks_accent_insensitive_duplicate(): void
    {
        // PostgreSQL only — SQLite has no unaccent. Skip on sqlite where the
        // accent-insensitive layer is best-effort via Rule fallback.
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Accent-insensitive matching requires Postgres unaccent extension.');
        }

        $this->actingAsSuperAdmin();
        Country::factory()->named('América del Sur')->create();

        $response = $this->post(route('system_management.countries.store'), $this->validCountryData([
            'name' => 'America del Sur',  // sin tilde
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Country::count());
    }

    public function test_create_trims_whitespace_for_duplicate_check(): void
    {
        $this->actingAsSuperAdmin();
        Country::factory()->named('Europa')->create();

        $response = $this->post(route('system_management.countries.store'), $this->validCountryData([
            'name' => '  Europa  ',
        ]));

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
        Country::factory()->named('Antártida')->trashed()->create();

        $response = $this->post(route('system_management.countries.store'), $this->validCountryData([
            'name' => 'Antártida',
        ]));

        $response->assertSessionHasNoErrors();
        // 1 active + 1 soft-deleted (factory state)
        $this->assertSame(1, Country::count());
        $this->assertSame(2, Country::withTrashed()->count());
    }

    public function test_update_can_save_same_name_for_same_record(): void
    {
        $this->actingAsSuperAdmin();
        $country = Country::factory()->named('Europa')->create();

        $response = $this->put(route('system_management.countries.update', $country->slug), $this->validCountryData(['name' => 'Europa', 'is_active' => true]));

        $response->assertSessionHasNoErrors();
    }

    public function test_update_blocks_duplicate_against_other_record(): void
    {
        $this->actingAsSuperAdmin();
        Country::factory()->named('Europa')->create();
        $other = Country::factory()->named('Asia')->create();

        $response = $this->put(route('system_management.countries.update', $other->slug), $this->validCountryData(['name' => 'Europa', 'is_active' => true]));

        $response->assertSessionHasErrors('name');
    }

    // ─── IMPORT-LEVEL ───────────────────────────────────────────────────────

    public function test_import_dry_run_flags_in_file_duplicates(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active'],
            ['Argentina', 'AR', 'ARS', 'America/Argentina/Buenos_Aires', 'América del Sur', 'es_AR', '1'],
            ['Argentina', 'AB', 'ARS', 'America/Argentina/Buenos_Aires', 'América del Sur', 'es_AR', '1'],   // duplicate name
            ['Brasil',    'BR', 'BRL', 'America/Sao_Paulo',              'América del Sur', 'es_AR', '1'],
        ]);

        $response = $this->post(
            route('system_management.countries.import'),
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
        $this->assertSame(0, Country::count());
    }

    public function test_import_commit_skips_existing_in_create_only_mode(): void
    {
        $this->actingAsSuperAdmin();
        Country::factory()->named('Argentina')->create();

        $csv = $this->csv([
            ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active'],
            ['Argentina', 'AA', 'ARS', 'America/Argentina/Buenos_Aires', 'América del Sur', 'es_AR', '1'],  // already exists → skip
            ['Brasil',    'BR', 'BRL', 'America/Sao_Paulo',              'América del Sur', 'es_AR', '1'],  // new → create
        ]);

        $response = $this->post(
            route('system_management.countries.import'),
            ['file' => $csv, 'mode' => 'create_only', 'dry_run' => 0],
        );

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(2, Country::count());
    }

    public function test_import_commit_updates_existing_in_update_or_create_mode(): void
    {
        $this->actingAsSuperAdmin();
        $argentina = Country::factory()->named('Argentina')->create(['is_active' => false]);

        $csv = $this->csv([
            ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active'],
            ['Argentina', 'AR', 'ARS', 'America/Argentina/Buenos_Aires', 'América del Sur', 'es_AR', '1'],  // exists → update is_active to true
        ]);

        $response = $this->post(
            route('system_management.countries.import'),
            ['file' => $csv, 'mode' => 'update_or_create', 'dry_run' => 0],
        );

        $response->assertOk();
        $this->assertTrue($argentina->fresh()->is_active);
    }

    public function test_import_dry_run_does_not_persist_changes(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active'],
            ['Argentina', 'AR', 'ARS', 'America/Argentina/Buenos_Aires', 'América del Sur', 'es_AR', '1'],
            ['Brasil',    'BR', 'BRL', 'America/Sao_Paulo',              'América del Sur', 'es_AR', '1'],
        ]);

        $this->post(
            route('system_management.countries.import'),
            ['file' => $csv, 'mode' => 'update_or_create', 'dry_run' => 1],
        );

        $this->assertSame(0, Country::count(), 'Dry-run must not persist any rows.');
    }

    public function test_import_rejects_rows_with_empty_name(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'iso_code', 'currency', 'timezone', 'region', 'default_locale', 'is_active'],
            ['',          'XX', 'XXX', 'UTC',                              'América del Sur', 'es_AR', '1'],  // empty name
            ['Brasil',    'BR', 'BRL', 'America/Sao_Paulo',                'América del Sur', 'es_AR', '1'],
        ]);

        $response = $this->post(
            route('system_management.countries.import'),
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
        return new UploadedFile($tmp, 'countries.csv', 'text/csv', null, true);
    }
}
