<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PUT /profile/preferences — apariencia (esquema de color + posición de menú),
 * guardada en BD (cross-device). Listas cerradas: valores fuera de la lista se
 * rechazan.
 */
class UpdatePreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Test Tenant', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web'], ['description' => 'Test user']);
    }

    protected function makeUser(): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $user->assignRole('user');
        return $user;
    }

    public function test_defaults_are_sap_and_top(): void
    {
        $user = $this->makeUser()->fresh();
        $this->assertSame('sap', $user->ui_scheme);
        $this->assertSame('top', $user->nav_position);
    }

    public function test_user_can_update_appearance(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->put(route('profile.preferences.update'), [
            'ui_scheme'    => 'emerald',
            'nav_position' => 'bottom',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('emerald', $user->ui_scheme);
        $this->assertSame('bottom', $user->nav_position);
    }

    public function test_invalid_scheme_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->put(route('profile.preferences.update'), [
            'ui_scheme'    => 'rainbow',
            'nav_position' => 'top',
        ])->assertSessionHasErrors('ui_scheme');

        $this->assertSame('sap', $user->fresh()->ui_scheme);
    }

    public function test_invalid_nav_position_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->put(route('profile.preferences.update'), [
            'ui_scheme'    => 'sap',
            'nav_position' => 'diagonal',
        ])->assertSessionHasErrors('nav_position');
    }
}
