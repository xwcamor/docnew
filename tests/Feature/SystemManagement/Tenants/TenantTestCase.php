<?php

namespace Tests\Feature\SystemManagement\Tenants;

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
 * Base test case para Tenants. Bootstrap tenant en id=1 (real) para que users
 * actingAs apunten ahí. Tenant::count() arranca en 1 (el bootstrap cuenta).
 *
 * NOTE: TenantService::create() llama ensureFor() que auto-crea un User (system).
 * Tests sensibles a User::count() deben tener en cuenta el delta.
 */
abstract class TenantTestCase extends TestCase
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
            'id' => 1, 'slug' => Str::random(22),
            'name' => 'América del Sur', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        DB::table('countries')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'region_id' => 1, 'default_locale_id' => 1,
            'name' => 'Argentina',
            'iso_code' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
        // Bootstrap soft-deleted: users.tenant_id apunta aquí vía FK pero
        // Tenant::count() arranca en 0 (SoftDeletes lo excluye del scope normal).
        // No hay columna tenants.plan — el plan se deriva de subscriptions.
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22),
            'name' => '__bootstrap_tenant__',
            'is_active' => false,
            'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => now(),
            'deleted_description' => 'Bootstrap fixture for tests.',
        ]]);
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['view', 'show', 'create', 'edit', 'delete', 'export'] as $action) {
            Permission::firstOrCreate(['name' => "tenants.{$action}", 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'user',        'guard_name' => 'web'], ['description' => 'Test user']);
        Role::firstOrCreate(['name' => 'api',         'guard_name' => 'web'], ['description' => 'Test api (system user)']);
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

    /**
     * Payload válido para create/update tenants vía POST/PUT.
     *
     * StoreRequest exige admin_* (un workspace sin admin es estado
     * inconsistente). UpdateRequest los ignora — los overrides permiten
     * quitarlos cuando se testea el update.
     */
    protected function validTenantData(array $overrides = []): array
    {
        $uid = uniqid();

        return array_merge([
            'name'           => 'Test Tenant ' . $uid,
            'plan'           => 'free',
            'is_active'      => true,
            'admin_name'     => 'Admin ' . $uid,
            'admin_email'    => "admin_{$uid}@example.com",
            'admin_password' => 'secret123',
        ], $overrides);
    }
}
