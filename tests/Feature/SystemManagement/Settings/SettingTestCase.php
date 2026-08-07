<?php

namespace Tests\Feature\SystemManagement\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

abstract class SettingTestCase extends TestCase
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
        $this->seedRolesAndPermissions();
    }

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
            'region_id' => 999, 'default_locale_id' => 1,
            'name' => 'Argentina', 'iso_code' => 'AR',
            'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'Test Tenant', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view', 'show', 'create', 'edit', 'delete', 'export'] as $action) {
            Permission::firstOrCreate(['name' => "settings.{$action}", 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'user',        'guard_name' => 'web'], ['description' => 'Test user']);
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $user->assignRole('super');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $user->assignRole('admin');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsRegularUser(): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $user->assignRole('user');
        $this->actingAs($user);
        return $user;
    }

    /** Payload válido para Settings. */
    protected static int $keyCounter = 0;
    protected function validSettingData(array $overrides = []): array
    {
        $n = ++self::$keyCounter;
        return array_merge([
            'name'        => 'TestSetting ' . $n,
            'key'         => 'test.setting_' . $n,
            'type'        => 'string',
            'value'       => 'test value',
            'group'       => 'test',
            'description' => 'Test description',
            'is_active'   => true,
        ], $overrides);
    }
}
