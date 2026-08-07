<?php

namespace Tests\Feature\UserManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base para tests de Users + Roles + Customers (los modulos per-tenant).
 *
 * Bootstrap minimo:
 *   - parent rows (language, locale, region, country, tenant)
 *   - roles canonicos (super, admin, api)
 *   - permisos minimos para users + roles
 *   - actingAsSuperAdmin() / actingAsTenantAdmin()
 */
abstract class UserTestCase extends TestCase
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
            'name' => 'Español (AR)', 'language_id' => 1, 'is_active' => true,
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
        // Plan enterprise para que los tests no choquen contra max_users del free
        // ni contra plan_feature middlewares. Features = TODAS true (enterprise
        // desbloquea todo). Tests dedicados a planes hacen su setup propio.
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
        DB::table('tenants')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'name' => 'Test Tenant 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'name' => 'Test Tenant 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // El plan se deriva de la suscripción vigente — no hay columna
        // tenants.plan. Ambos tenants en enterprise vía suscripción activa,
        // para que no choquen contra plan_feature middlewares.
        DB::table('subscriptions')->insertOrIgnore([
            ['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'tenant_id' => 2, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['users.view', 'users.create', 'users.edit', 'users.delete', 'users.show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        Role::firstOrCreate(['name' => 'api', 'guard_name' => 'web'], ['description' => 'Test api']);
        $admin->syncPermissions(Permission::all());
    }

    protected function actingAsSuperAdmin(): User
    {
        $u = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');
        $this->actingAs($u);
        return $u;
    }

    protected function actingAsTenantAdmin(int $tenantId = 1): User
    {
        $u = User::factory()->create(['tenant_id' => $tenantId, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $this->actingAs($u);
        return $u;
    }
}
