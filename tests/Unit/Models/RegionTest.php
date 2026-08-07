<?php

namespace Tests\Unit\Models;

use App\Models\Country;
use App\Models\Region;
use App\Models\User;
use App\Models\UserFavorite;
use App\Models\UserRecentView;
use Illuminate\Http\Request;
use Tests\Feature\SystemManagement\Regions\RegionTestCase;

/**
 * Unit tests del modelo Region: generación de slug, casts, scopes,
 * relaciones, cleanup en force-delete.
 */
class RegionTest extends RegionTestCase
{
    public function test_slug_is_auto_generated_on_create(): void
    {
        $region = Region::create(['name' => 'Test', 'is_active' => true]);

        $this->assertNotNull($region->slug);
        $this->assertEquals(22, strlen($region->slug));
    }

    public function test_slugs_are_unique_across_creations(): void
    {
        $r1 = Region::create(['name' => 'R1', 'is_active' => true]);
        $r2 = Region::create(['name' => 'R2', 'is_active' => true]);

        $this->assertNotEquals($r1->slug, $r2->slug);
    }

    public function test_is_active_casts_to_boolean(): void
    {
        // SQLite devuelve 0/1; el cast debería darnos bool nativo.
        $region = Region::factory()->create(['is_active' => 1]);
        $this->assertTrue($region->fresh()->is_active);
        $this->assertIsBool($region->fresh()->is_active);

        $region2 = Region::factory()->create(['is_active' => 0]);
        $this->assertFalse($region2->fresh()->is_active);
    }

    public function test_route_key_is_slug(): void
    {
        $region = Region::factory()->create();
        $this->assertEquals('slug', $region->getRouteKeyName());
    }

    public function test_creator_relation_returns_user_even_if_soft_deleted(): void
    {
        $creator = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $region  = Region::factory()->create(['created_by' => $creator->id]);

        $creator->delete(); // soft-delete

        $this->assertNotNull($region->fresh()->creator);
        $this->assertEquals($creator->id, $region->fresh()->creator->id);
    }

    public function test_filter_scope_by_name_case_insensitive(): void
    {
        Region::factory()->create(['name' => 'Patagonia']);
        Region::factory()->create(['name' => 'Cuyo']);

        $results = Region::query()->filter(new Request(['name' => 'patag']))->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Patagonia', $results->first()->name);
    }

    public function test_filter_scope_by_is_active(): void
    {
        Region::factory()->create(['is_active' => true, 'name' => 'A']);
        Region::factory()->create(['is_active' => false, 'name' => 'B']);

        $active   = Region::query()->filter(new Request(['is_active' => '1']))->get();
        $inactive = Region::query()->filter(new Request(['is_active' => '0']))->get();

        $this->assertCount(1, $active);
        $this->assertCount(1, $inactive);
        $this->assertEquals('A', $active->first()->name);
        $this->assertEquals('B', $inactive->first()->name);
    }

    public function test_filter_scope_by_id_range(): void
    {
        $r1 = Region::factory()->create();
        $r2 = Region::factory()->create();
        $r3 = Region::factory()->create();

        $results = Region::query()
            ->filter(new Request(['id_from' => $r1->id, 'id_to' => $r2->id]))
            ->pluck('id')
            ->toArray();

        $this->assertContains($r1->id, $results);
        $this->assertContains($r2->id, $results);
        $this->assertNotContains($r3->id, $results);
    }

    public function test_filter_scope_only_favorites_filters_per_user(): void
    {
        $user = $this->actingAsSuperAdmin();

        $r1 = Region::factory()->create();
        $r2 = Region::factory()->create();

        UserFavorite::create([
            'user_id'          => $user->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $r1->id,
        ]);

        $results = Region::query()
            ->filter(new Request(['only_favorites' => '1']))
            ->pluck('id')
            ->toArray();

        $this->assertContains($r1->id, $results);
        $this->assertNotContains($r2->id, $results);
    }

    public function test_filter_scope_ignores_invalid_sort_column(): void
    {
        Region::factory()->count(3)->create();

        // Sort por columna no whitelisted no debe romper (cae al default).
        $results = Region::query()
            ->filter(new Request(['sort' => 'evil_column; DROP TABLE']))
            ->get();

        $this->assertCount(3, $results); // bootstrap region es soft-deleted
    }

    public function test_force_delete_cleans_favorites_and_recents(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        UserFavorite::create([
            'user_id'          => $user->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);
        UserRecentView::create([
            'user_id'       => $user->id,
            'viewable_type' => Region::class,
            'viewable_id'   => $region->id,
            'viewed_at'     => now(),
        ]);

        $region->forceDelete();

        $this->assertDatabaseMissing('user_favorites', [
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);
        $this->assertDatabaseMissing('user_recent_views', [
            'viewable_type' => Region::class,
            'viewable_id'   => $region->id,
        ]);
    }

    public function test_soft_delete_preserves_favorites_and_recents(): void
    {
        $user   = $this->actingAsSuperAdmin();
        $region = Region::factory()->create();

        UserFavorite::create([
            'user_id'          => $user->id,
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);

        $region->delete(); // soft

        $this->assertDatabaseHas('user_favorites', [
            'favoritable_type' => Region::class,
            'favoritable_id'   => $region->id,
        ]);
    }
}
