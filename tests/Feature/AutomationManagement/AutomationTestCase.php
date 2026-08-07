<?php

namespace Tests\Feature\AutomationManagement;

use App\Models\Plan;
use App\Models\Subscription;
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
 * Base para tests de Automations. Crea un tenant con plan enterprise (que
 * tiene la feature `automations`) y un admin del tenant que puede crearlas.
 */
abstract class AutomationTestCase extends TestCase
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
        $this->seedEnterprisePlan();
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
        // El plan se deriva de la suscripción vigente. El tenant se crea sin
        // plan; seedEnterprisePlan() le agrega la suscripción.
        DB::table('tenants')->insertOrIgnore([[
            'id' => 1, 'slug' => Str::random(22), 'name' => 'Enterprise Tenant',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]]);
    }

    protected function seedRolesAndPermissions(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        Role::firstOrCreate(['name' => 'admin',       'guard_name' => 'web'], ['description' => 'Test admin']);
    }

    /**
     * Crea el plan enterprise + una suscripción activa para el tenant 1.
     * Sin esto, el middleware plan_feature:automations bloquearía las rutas.
     */
    protected function seedEnterprisePlan(): void
    {
        Plan::factory()->create([
            'slug'      => 'enterprise',
            'name'      => 'Enterprise',
            'features'  => ['automations' => true],
            'is_active' => true,
            'is_public' => true,
        ]);

        Subscription::create([
            'tenant_id'  => 1,
            'plan'       => 'enterprise',
            'status'     => Subscription::STATUS_ACTIVE,
            'starts_at'  => now()->subDay(),
            'ends_at'    => now()->addYear(),
            'created_by' => null,
        ]);
    }

    protected function actingAsTenantAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
        ]);
        $user->assignRole('admin');
        $this->actingAs($user);
        return $user;
    }

    protected function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create([
            'tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1,
        ]);
        $user->assignRole('super');
        $this->actingAs($user);
        return $user;
    }
}
