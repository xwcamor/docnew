<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Support\Tz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests del endpoint PUT /profile cuando incluye `timezone`.
 *
 * El user puede:
 *   - Setear un TZ propio (override del workspace).
 *   - Mandar '' o no mandar nada para heredar del workspace (timezone = null).
 *
 * TZ inválidos son rechazados por la validación contra Tz::availableTimezones().
 */
class UpdateTimezoneTest extends TestCase
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
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web'], ['description' => 'Test user']);
    }

    protected function seedParentRows(): void
    {
        DB::table('languages')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Spanish', 'iso_code' => 'es',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'code' => 'es_AR', 'name' => 'Español (Argentina)',
            'language_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'name' => '__bootstrap_region__',
            'is_active' => false,
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
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Test Tenant', 'is_active' => true,
            'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    protected function makeUser(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 1,
            'locale_id'  => 1,
            'timezone'   => null,
        ]);
        $user->assignRole('user');
        return $user;
    }

    public function test_user_can_update_own_timezone(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->put(route('profile.update'), [
            'name'     => $user->name,
            'timezone' => 'America/Lima',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('America/Lima', $user->fresh()->timezone);
    }

    public function test_user_can_reset_to_inherit(): void
    {
        // Arrancamos con TZ propio, luego lo limpiamos para volver a heredar.
        $user = $this->makeUser();
        $user->timezone = 'America/Lima';
        $user->save();
        $this->actingAs($user);

        $response = $this->put(route('profile.update'), [
            'name'     => $user->name,
            'timezone' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->timezone);
    }

    public function test_invalid_timezone_rejected(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->put(route('profile.update'), [
            'name'     => $user->name,
            'timezone' => 'Not/A_Real_Zone',
        ]);

        $response->assertSessionHasErrors('timezone');
        $this->assertNull($user->fresh()->timezone);
    }
}
