<?php

namespace Tests\Feature\Communication;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base case para tests del modulo Communication (Messages + Inbox).
 *
 * Crea: tenants, users humanos en cada tenant, roles super/admin/user, y
 * los users adicionales requeridos para audiencias global/tenant/user.
 */
abstract class MessageTestCase extends TestCase
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
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish',
            'iso_code' => 'es', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('locales')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR',
            'name' => 'Espanol (AR)', 'language_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('regions')->insertOrIgnore([[
            'id' => 999, 'slug' => Str::random(22), 'name' => '__bootstrap__',
            'is_active' => false, 'deleted_at' => now(),
            'deleted_description' => 'Bootstrap',
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'region_id' => 999,
            'name' => 'Argentina', 'iso_code' => 'AR', 'currency' => 'ARS',
            'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('tenants')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'name' => 'Tenant 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'name' => 'Tenant 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web'], ['description' => 'Test user']);
        Role::firstOrCreate(['name' => 'api',   'guard_name' => 'web'], ['description' => 'API tokens']);
    }

    protected function makeUser(?int $tenantId, string $role = 'user', string $emailPrefix = 'u'): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenantId,
            'country_id' => 1,
            'locale_id'  => 1,
            'email'      => $emailPrefix . '_' . Str::random(6) . '@test.com',
        ]);
        $user->assignRole($role);
        return $user;
    }

    protected function actingAsSuper(): User
    {
        $user = $this->makeUser(null, 'super', 'super');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsAdmin(int $tenantId = 1): User
    {
        $user = $this->makeUser($tenantId, 'admin', 'admin');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsCustomer(int $tenantId = 1): User
    {
        $user = $this->makeUser($tenantId, 'user', 'user');
        $this->actingAs($user);
        return $user;
    }
}
