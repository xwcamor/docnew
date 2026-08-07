<?php

namespace Tests\Feature;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Locks down the saved-views CRUD endpoint behavior:
 *   - User-scoped: nadie puede leer/modificar la vista de otro usuario
 *   - Validación: name + state + module requeridos
 *   - Constraint single default: solo una vista is_default por (user, module)
 *   - Soft constraints aplicadas correctamente al cambiar default
 */
class SavedViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);
        $this->seedParentRows();
    }

    /** Inserta las parent rows mínimas que User FKs requieren. */
    protected function seedParentRows(): void
    {
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'code' => 'es_AR', 'name' => 'Español (AR)', 'language_id' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'name' => '__bootstrap__', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'region_id' => 999, 'name' => 'Argentina',
            'iso_code' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires',
            'default_locale_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        // Plan enterprise (todas las features true) — saved-views esta gateado
        // por plan_feature:saved_views, sin un plan que la incluya el middleware
        // devuelve 402.
        DB::table('plans')->insertOrIgnore([[
            'id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise',
            'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1,
            'export_rate_limit' => 50, 'support_level' => 'priority',
            'features' => json_encode([
                'export_csv' => true, 'export_excel' => true, 'export_pdf' => true,
                'export_word' => true, 'branded_exports' => true,
                'audit_log_view' => true, 'saved_views' => true,
                'bulk_operations' => true, 'imports' => true, 'edit_all' => true,
                'team_management' => true, 'api_access' => true, 'automations' => true,
                'scheduled_exports' => true, 'export_webhook_delivery' => true,
                'export_email_delivery' => true, 'extended_retention' => true,
                'higher_export_rate_limit' => true,
            ]),
            'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD',
            'is_active' => true, 'is_public' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Test',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        // El plan se deriva de la suscripción vigente — no hay columna
        // tenants.plan. Suscripción enterprise activa para tenant 1.
        DB::table('subscriptions')->insertOrIgnore([[
            'id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(),
            'currency' => 'USD', 'payment_method' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    protected function actingAsUser(): User
    {
        $user = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
        ]);
        $this->actingAs($user);
        return $user;
    }

    // ─── INDEX ──────────────────────────────────────────────────────────────

    public function test_index_returns_only_user_views_for_module(): void
    {
        $me      = $this->actingAsUser();
        $other   = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);

        SavedView::create(['user_id' => $me->id,    'module' => 'regions', 'name' => 'Mine A',  'state' => ['x' => 1]]);
        SavedView::create(['user_id' => $me->id,    'module' => 'regions', 'name' => 'Mine B',  'state' => ['x' => 2]]);
        SavedView::create(['user_id' => $me->id,    'module' => 'users',   'name' => 'Other M', 'state' => ['x' => 3]]);
        SavedView::create(['user_id' => $other->id, 'module' => 'regions', 'name' => 'Theirs',  'state' => ['x' => 4]]);

        $response = $this->getJson(route('saved_views.index', ['module' => 'regions']));

        $response->assertOk();
        $names = collect($response->json('views'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Mine A', 'Mine B'], $names);
    }

    public function test_index_requires_module_param(): void
    {
        $this->actingAsUser();
        $response = $this->getJson(route('saved_views.index'));
        $response->assertStatus(422);
    }

    // ─── STORE ──────────────────────────────────────────────────────────────

    public function test_store_creates_a_new_view(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson(route('saved_views.store'), [
            'module' => 'regions',
            'name'   => 'Mis activos',
            'state'  => ['filters' => ['is_active' => true]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('user_saved_views', [
            'user_id' => $user->id,
            'module'  => 'regions',
            'name'    => 'Mis activos',
        ]);
    }

    public function test_store_requires_name_and_state_and_module(): void
    {
        $this->actingAsUser();
        $response = $this->postJson(route('saved_views.store'), []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'state', 'module']);
    }

    public function test_store_marks_only_one_default_per_user_module(): void
    {
        $user = $this->actingAsUser();

        SavedView::create([
            'user_id' => $user->id, 'module' => 'regions',
            'name' => 'Old default', 'is_default' => true,
            'state' => ['x' => 1],
        ]);

        $this->postJson(route('saved_views.store'), [
            'module'     => 'regions',
            'name'       => 'New default',
            'is_default' => true,
            'state'      => ['x' => 2],
        ])->assertCreated();

        $defaults = SavedView::forUser($user->id)->forModule('regions')
            ->where('is_default', true)->pluck('name')->all();

        $this->assertEquals(['New default'], $defaults, 'Solo la nueva default debe quedar marcada.');
    }

    // ─── UPDATE ─────────────────────────────────────────────────────────────

    public function test_update_renames_a_view(): void
    {
        $user = $this->actingAsUser();
        $view = SavedView::create([
            'user_id' => $user->id, 'module' => 'regions',
            'name' => 'Original', 'state' => ['x' => 1],
        ]);

        $this->putJson(route('saved_views.update', $view->id), [
            'name'  => 'Renombrada',
            'state' => $view->state,
        ])->assertOk();

        $this->assertSame('Renombrada', $view->fresh()->name);
    }

    public function test_update_cannot_touch_other_users_view(): void
    {
        $other = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $view  = SavedView::create([
            'user_id' => $other->id, 'module' => 'regions',
            'name' => 'Theirs', 'state' => ['x' => 1],
        ]);

        $this->actingAsUser();  // diferente user

        $this->putJson(route('saved_views.update', $view->id), [
            'name' => 'Hacked', 'state' => ['x' => 2],
        ])->assertNotFound();

        $this->assertSame('Theirs', $view->fresh()->name);
    }

    public function test_update_swapping_default_unmarks_the_previous(): void
    {
        $user = $this->actingAsUser();
        $a = SavedView::create([
            'user_id' => $user->id, 'module' => 'regions',
            'name' => 'A', 'is_default' => true, 'state' => ['x' => 1],
        ]);
        $b = SavedView::create([
            'user_id' => $user->id, 'module' => 'regions',
            'name' => 'B', 'is_default' => false, 'state' => ['x' => 2],
        ]);

        $this->putJson(route('saved_views.update', $b->id), [
            'name' => 'B', 'is_default' => true, 'state' => ['x' => 2],
        ])->assertOk();

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
    }

    // ─── DESTROY ────────────────────────────────────────────────────────────

    public function test_destroy_deletes_only_own_view(): void
    {
        $me   = $this->actingAsUser();
        $mine = SavedView::create([
            'user_id' => $me->id, 'module' => 'regions',
            'name' => 'Mine', 'state' => ['x' => 1],
        ]);

        $this->deleteJson(route('saved_views.destroy', $mine->id))->assertOk();
        $this->assertDatabaseMissing('user_saved_views', ['id' => $mine->id]);
    }

    public function test_destroy_blocks_other_users_view(): void
    {
        $other = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $view  = SavedView::create([
            'user_id' => $other->id, 'module' => 'regions',
            'name' => 'Theirs', 'state' => ['x' => 1],
        ]);

        $this->actingAsUser();

        $this->deleteJson(route('saved_views.destroy', $view->id))->assertNotFound();
        $this->assertDatabaseHas('user_saved_views', ['id' => $view->id]);
    }
}
