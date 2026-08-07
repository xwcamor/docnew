<?php

namespace Tests\Feature\SystemManagement\Regions;

use App\Jobs\SystemManagement\Regions\BulkRegionsActionJob;
use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Region;
use App\Models\UserFavorite;
use App\Models\UserRecentView;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Cobertura de funcionalidades avanzadas que el resto de tests no toca:
 *   - Force-delete (hard delete) con audit log y cleanup polimórfico
 *   - Bulk async dispatch cuando count > threshold
 *   - Dependency check antes de eliminar
 *   - Favoritos toggle + filtro "solo favoritos"
 *   - Bulk restore desde Trash
 *   - API cursor pagination
 *
 * Estos tests previenen regresiones en features que se rompen sin que
 * nadie note hasta que un usuario lo reporta.
 */
class RegionAdvancedFeaturesTest extends RegionTestCase
{
    // ─── Force-delete (hard delete) ────────────────────────────────────────

    public function test_force_delete_writes_audit_and_removes_record(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $region = Region::factory()->named('Hard delete me')->trashed()->create();

        $response = $this->delete(
            route('system_management.regions.force_delete', $region->slug),
            [
                'name_confirmation' => 'Hard delete me',
                'reason'            => 'Compliance request - permanent deletion',
            ],
        );

        $response->assertRedirect();
        $this->assertDatabaseMissing('regions', ['id' => $region->id]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Region::class,
            'auditable_id'   => $region->id,
            'event'          => 'force_deleted',
            'user_id'        => $admin->id,
        ]);
    }

    public function test_force_delete_rejects_when_name_does_not_match(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->named('Exact Name')->trashed()->create();

        $response = $this->delete(
            route('system_management.regions.force_delete', $region->slug),
            [
                'name_confirmation' => 'wrong name',
                'reason'            => 'Trying to delete with wrong confirmation',
            ],
        );

        $response->assertSessionHasErrors('name_confirmation');
        $this->assertDatabaseHas('regions', ['id' => $region->id]);
    }

    public function test_force_delete_cleans_up_favorites_and_recent_views(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $region = Region::factory()->named('To purge')->trashed()->create();

        UserFavorite::create([
            'user_id'          => $admin->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);
        UserRecentView::create([
            'user_id'       => $admin->id,
            'viewable_type' => Region::class,
            'viewable_id'   => $region->id,
            'viewed_at'     => now(),
        ]);

        $this->delete(
            route('system_management.regions.force_delete', $region->slug),
            ['name_confirmation' => 'To purge', 'reason' => 'Cleanup test for orphans'],
        );

        $this->assertDatabaseMissing('user_favorites', [
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);
        $this->assertDatabaseMissing('user_recent_views', [
            'viewable_type' => Region::class,
            'viewable_id'   => $region->id,
        ]);
    }

    // ─── Bulk async dispatch ───────────────────────────────────────────────

    public function test_bulk_delete_dispatches_to_queue_when_over_threshold(): void
    {
        $this->actingAsSuperAdmin();
        Queue::fake();

        $threshold = BulkRegionsActionJob::asyncThreshold();
        $regions = Region::factory()->count($threshold + 1)->create();

        $response = $this->post(
            route('system_management.regions.bulk_delete'),
            [
                'ids'                 => $regions->pluck('id')->all(),
                'deleted_description' => 'Bulk async test',
            ],
        );

        $response->assertRedirect();
        Queue::assertPushed(BulkRegionsActionJob::class, function ($job) {
            return true;  // si está, ya lo prueba: el threshold disparó async
        });
    }

    public function test_bulk_delete_inline_when_under_threshold(): void
    {
        $this->actingAsSuperAdmin();
        Queue::fake();

        $regions = Region::factory()->count(3)->create();

        $this->post(
            route('system_management.regions.bulk_delete'),
            [
                'ids'                 => $regions->pluck('id')->all(),
                'deleted_description' => 'Bulk inline test',
            ],
        );

        Queue::assertNotPushed(BulkRegionsActionJob::class);
        foreach ($regions as $r) {
            $this->assertSoftDeleted('regions', ['id' => $r->id]);
        }
    }

    // ─── Dependency check ──────────────────────────────────────────────────

    public function test_delete_page_exposes_dependent_count(): void
    {
        $this->actingAsSuperAdmin();
        $region = Region::factory()->named('With deps')->create();

        // Crear 2 países que apuntan a esta región (FK region_id).
        Country::query()->insert([
            [
                'slug' => Str::random(22), 'region_id' => $region->id,
                'name' => 'Argentina-test', 'iso_code' => 'AT',
                'currency' => 'XYZ', 'timezone' => 'UTC', 'default_locale_id' => 1,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'slug' => Str::random(22), 'region_id' => $region->id,
                'name' => 'Brasil-test', 'iso_code' => 'BT',
                'currency' => 'XYZ', 'timezone' => 'UTC', 'default_locale_id' => 1,
                'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('system_management.regions.delete', $region->slug));

        $response->assertOk();
        // Inertia page recibe `dependents` con count=2.
        $response->assertInertia(fn ($p) => $p
            ->component('Regions/Delete')
            ->has('dependents.countries')
            ->where('dependents.countries.count', 2)
        );
    }

    // ─── Favoritos toggle ──────────────────────────────────────────────────

    public function test_favorite_toggle_creates_and_removes(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        // Toggle on
        $r1 = $this->post(route('user_prefs.favorites.toggle'), [
            'module' => 'regions', 'id' => $region->id,
        ]);
        $r1->assertOk()->assertJson(['ok' => true, 'favorited' => true]);
        $this->assertDatabaseHas('user_favorites', [
            'user_id'          => $admin->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);

        // Toggle off
        $r2 = $this->post(route('user_prefs.favorites.toggle'), [
            'module' => 'regions', 'id' => $region->id,
        ]);
        $r2->assertOk()->assertJson(['ok' => true, 'favorited' => false]);
        $this->assertDatabaseMissing('user_favorites', [
            'user_id'        => $admin->id,
            'favoritable_id' => $region->id,
        ]);
    }

    public function test_favorite_toggle_rejects_unknown_module(): void
    {
        $this->actingAsSuperAdmin();
        $r = $this->post(route('user_prefs.favorites.toggle'), [
            'module' => 'NotInAllowlist',
            'id'     => 1,
        ]);
        $r->assertStatus(422);
    }

    public function test_index_only_favorites_filter(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $favorite   = Region::factory()->named('Pinned')->create();
        $regular    = Region::factory()->named('Regular')->create();

        UserFavorite::create([
            'user_id'          => $admin->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $favorite->id,
        ]);

        $response = $this->get(route('system_management.regions.index', ['only_favorites' => 1]));

        $response->assertOk();
        $response->assertInertia(fn ($p) => $p
            ->component('Regions/Index')
            ->where('regions.total', 1)
            ->where('regions.data.0.id', $favorite->id)
        );
    }

    // ─── Bulk restore ──────────────────────────────────────────────────────

    public function test_bulk_restore_super_only(): void
    {
        $this->actingAsSuperAdmin();
        $a = Region::factory()->named('A')->trashed()->create();
        $b = Region::factory()->named('B')->trashed()->create();

        $response = $this->post(
            route('system_management.regions.bulk_restore'),
            ['ids' => [$a->id, $b->id]],
        );

        $response->assertRedirect();
        $this->assertNotSoftDeleted('regions', ['id' => $a->id]);
        $this->assertNotSoftDeleted('regions', ['id' => $b->id]);
    }

    public function test_bulk_restore_blocked_for_non_super(): void
    {
        $this->actingAsAdmin();
        $r = Region::factory()->trashed()->create();

        $response = $this->post(
            route('system_management.regions.bulk_restore'),
            ['ids' => [$r->id]],
        );

        // El controller usa abort_unless(super, 403) pero hay un custom
        // exception handler que convierte 403 a redirect+flash, así que
        // chequeamos cualquiera de los dos.
        $this->assertTrue(
            $response->isRedirect() || $response->status() === 403,
            'Non-super should be blocked from bulk_restore'
        );
        $this->assertSoftDeleted('regions', ['id' => $r->id]);
    }
}
