<?php

namespace Tests\Feature\SystemManagement\Locales;

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
 * Base test case para Locales. Bootstrap soft-deleted en id=999 para
 * que users.locale_id tenga a quien apuntar sin afectar Locale::count().
 */
abstract class LocaleTestCase extends TestCase
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
            'name' => 'Spanish', 'iso_code' => 'es',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('languages')->insertOrIgnore([[
            'id' => 2, 'slug' => Str::random(22),
            'name' => 'English', 'iso_code' => 'en',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);

        DB::table('locales')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'code' => 'zz_ZZ', 'name' => '__bootstrap_locale__',
            'language_id' => 1, 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
        ]]);

        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'name' => '__bootstrap_region__', 'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22),
            'region_id' => 999, 'default_locale_id' => 999,
            'name' => '__bootstrap_country__',
            'iso_code' => 'ZZ', 'currency' => 'XXX', 'timezone' => 'UTC',
            'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
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
            Permission::firstOrCreate(['name' => "locales.{$action}", 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'user',        'guard_name' => 'web'], ['description' => 'Test user']);
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 999,
            'locale_id'  => 999,
        ]);
        $user->assignRole('super');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 999,
            'locale_id'  => 999,
        ]);
        $user->assignRole('admin');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsRegularUser(): User
    {
        $user = User::factory()->create([
            'tenant_id'  => 1,
            'country_id' => 999,
            'locale_id'  => 999,
        ]);
        $user->assignRole('user');
        $this->actingAs($user);
        return $user;
    }

    /**
     * Payload mínimo válido para Locale create/update. Genera code BCP-47
     * único secuencial: aa_AA, ab_AA, …, zz_AA, aa_AB, …
     */
    protected static int $codeCounter = 0;

    protected function validLocaleData(array $overrides = []): array
    {
        $n = ++self::$codeCounter;
        $lang1 = chr(ord('a') + intdiv($n - 1, 26) % 26);
        $lang2 = chr(ord('a') + ($n - 1) % 26);
        $regBlock = intdiv($n - 1, 676);
        $reg1 = chr(ord('A') + intdiv($regBlock, 26) % 26);
        $reg2 = chr(ord('A') + $regBlock % 26);
        $code = "{$lang1}{$lang2}_{$reg1}{$reg2}";

        return array_merge([
            'name'        => 'Test Locale ' . $n,
            'code'        => $code,
            'language_id' => 1,
        ], $overrides);
    }
}
