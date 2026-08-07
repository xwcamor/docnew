<?php

namespace Tests\Feature\SystemManagement\Locales;

use App\Models\Locale;
use Illuminate\Http\UploadedFile;

/**
 * Locks down the 3-layer duplicate prevention for Locales:
 *
 *   1. Form submission (StoreRequest / UpdateRequest via UniqueNormalizedName Rule)
 *   2. Excel/CSV import (LocalesImport: in-file dedup + DB lookup)
 *   3. DB-level partial unique index (final safety net)
 *
 * If you clone Locales to build a new module, copy this file and rename.
 */
class LocaleDuplicatePreventionTest extends LocaleTestCase
{
    // ─── FORM-LEVEL ─────────────────────────────────────────────────────────

    public function test_create_blocks_exact_name_duplicate(): void
    {
        $this->actingAsSuperAdmin();
        Locale::factory()->named('América del Sur')->create();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
            'name' => 'América del Sur',
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Locale::count());
    }

    public function test_create_blocks_case_insensitive_duplicate(): void
    {
        // Use ASCII-only variants so the test passes on both Postgres (full
        // Unicode case folding) and SQLite (LOWER only folds ASCII by default).
        $this->actingAsSuperAdmin();
        Locale::factory()->named('Europa')->create();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
            'name' => 'EUROPA',
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Locale::count());
    }

    public function test_create_blocks_accent_insensitive_duplicate(): void
    {
        // PostgreSQL only — SQLite has no unaccent. Skip on sqlite where the
        // accent-insensitive layer is best-effort via Rule fallback.
        if (\DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Accent-insensitive matching requires Postgres unaccent extension.');
        }

        $this->actingAsSuperAdmin();
        Locale::factory()->named('América del Sur')->create();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
            'name' => 'America del Sur',  // sin tilde
        ]));

        $response->assertSessionHasErrors('name');
        $this->assertSame(1, Locale::count());
    }

    public function test_create_trims_whitespace_for_duplicate_check(): void
    {
        $this->actingAsSuperAdmin();
        Locale::factory()->named('Europa')->create();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
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
        Locale::factory()->named('Antártida')->trashed()->create();

        $response = $this->post(route('system_management.locales.store'), $this->validLocaleData([
            'name' => 'Antártida',
        ]));

        $response->assertSessionHasNoErrors();
        // 1 active + 1 soft-deleted (factory state)
        $this->assertSame(1, Locale::count());
        $this->assertSame(2, Locale::withTrashed()->count());
    }

    public function test_update_can_save_same_name_for_same_record(): void
    {
        $this->actingAsSuperAdmin();
        $locale = Locale::factory()->named('Europa')->create();

        $response = $this->put(route('system_management.locales.update', $locale->slug), $this->validLocaleData(['name' => 'Europa', 'is_active' => true]));

        $response->assertSessionHasNoErrors();
    }

    public function test_update_blocks_duplicate_against_other_record(): void
    {
        $this->actingAsSuperAdmin();
        Locale::factory()->named('Europa')->create();
        $other = Locale::factory()->named('Asia')->create();

        $response = $this->put(route('system_management.locales.update', $other->slug), $this->validLocaleData(['name' => 'Europa', 'is_active' => true]));

        $response->assertSessionHasErrors('name');
    }

    // ─── IMPORT-LEVEL ───────────────────────────────────────────────────────

    public function test_import_dry_run_flags_in_file_duplicates(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'code', 'language', 'is_active'],
            ['Español (Argentina)', 'es_AR', 'es', '1'],
            ['Español (Argentina)', 'es_CO', 'es', '1'],   // duplicate name (row 3)
            ['English (US)',        'en_US', 'en', '1'],
        ]);

        $response = $this->post(
            route('system_management.locales.import'),
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
        $this->assertSame(0, Locale::count());
    }

    public function test_import_commit_skips_existing_in_create_only_mode(): void
    {
        $this->actingAsSuperAdmin();
        Locale::factory()->named('Español (Argentina)')->forLanguage(1)->create();

        $csv = $this->csv([
            ['name', 'code', 'language', 'is_active'],
            ['Español (Argentina)', 'es_AR', 'es', '1'],   // already exists → skip
            ['English (US)',        'en_US', 'en', '1'],   // new → create
        ]);

        $response = $this->post(
            route('system_management.locales.import'),
            ['file' => $csv, 'mode' => 'create_only', 'dry_run' => 0],
        );

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertSame(1, $summary['created']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(2, Locale::count());
    }

    public function test_import_commit_updates_existing_in_update_or_create_mode(): void
    {
        $this->actingAsSuperAdmin();
        $europa = Locale::factory()->named('Español (Argentina)')->forLanguage(1)->create(['code' => 'es_AR', 'is_active' => false]);

        $csv = $this->csv([
            ['name', 'code', 'language', 'is_active'],
            ['Español (Argentina)', 'es_AR', 'es', '1'],   // exists → update is_active to true
        ]);

        $response = $this->post(
            route('system_management.locales.import'),
            ['file' => $csv, 'mode' => 'update_or_create', 'dry_run' => 0],
        );

        $response->assertOk();
        $this->assertTrue($europa->fresh()->is_active);
    }

    public function test_import_dry_run_does_not_persist_changes(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'code', 'language', 'is_active'],
            ['Español (Argentina)', 'es_AR', 'es', '1'],
            ['English (US)',        'en_US', 'en', '1'],
        ]);

        $this->post(
            route('system_management.locales.import'),
            ['file' => $csv, 'mode' => 'update_or_create', 'dry_run' => 1],
        );

        $this->assertSame(0, Locale::count(), 'Dry-run must not persist any rows.');
    }

    public function test_import_rejects_rows_with_empty_name(): void
    {
        $this->actingAsSuperAdmin();

        $csv = $this->csv([
            ['name', 'code', 'language', 'is_active'],
            ['',                    'zz_ZZ', 'es', '1'],   // empty name
            ['English (US)',        'en_US', 'en', '1'],   // valid
        ]);

        $response = $this->post(
            route('system_management.locales.import'),
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
        return new UploadedFile($tmp, 'locales.csv', 'text/csv', null, true);
    }
}
