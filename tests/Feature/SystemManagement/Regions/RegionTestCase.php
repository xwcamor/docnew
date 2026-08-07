<?php

namespace Tests\Feature\SystemManagement\Regions;

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

/**
 * Base test case for Regions feature tests.
 *
 * Bootstraps the minimal world needed:
 *   - Parent rows for User foreign keys (tenant, country, locale, language, region placeholder)
 *   - Roles (super, admin, user)
 *   - Region permissions
 *
 * Skips heavy seeders to keep tests fast — uses raw DB inserts to bypass model
 * events and global scopes.
 */
abstract class RegionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // LaravelLocalization redirects URL-less-prefix → /es/... by default,
        // which makes every test see a 302 instead of the actual response.
        // We disable both redirect middlewares globally for the test class so
        // assertions can target the response handler directly.
        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        $this->seedParentRows();
        $this->seedRolesAndPermissions();
    }

    /** Insert the minimal parent rows that User FKs require. */
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

        // Placeholder region needed because countries.region_id is NOT NULL with FK.
        // We give it id=999 + deleted_at so production tests can freely use 1..N
        // for their own data without colliding, AND Region::count() / Region::all()
        // ignore this row thanks to the SoftDeletes scope. The country FK still
        // resolves because the row physically exists.
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
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    /** Create roles + region permissions manually (faster than running the seeder). */
    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view', 'show', 'create', 'edit', 'delete', 'export'] as $action) {
            Permission::firstOrCreate(['name' => "regions.{$action}", 'guard_name' => 'web']);
        }

        // The roles table in this project extends Spatie's with a NOT NULL
        // `description` column, so we must always pass one when creating.
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'user',        'guard_name' => 'web'], ['description' => 'Test user']);
    }

    /** Create + return an authenticated super user. */
    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 1,
            'locale_id'  => 1,
        ]);
        $user->assignRole('super');
        $this->actingAs($user);
        return $user;
    }

    /** Create + return an authenticated admin (NO super powers). */
    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 1,
            'locale_id'  => 1,
        ]);
        $user->assignRole('admin');
        $this->actingAs($user);
        return $user;
    }

    /** Create + return an authenticated regular user (no special roles). */
    protected function actingAsRegularUser(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 1,
            'locale_id'  => 1,
        ]);
        $user->assignRole('user');
        $this->actingAs($user);
        return $user;
    }
}
