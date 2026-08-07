<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verifica que los permisos `{m}.export` / `{m}.import` se consultan como
 * gates REALES en las rutas de export/import de los módulos de negocio.
 *
 * Antes estaban "muertos": las rutas de export solo pedían `{m}.view` + plan,
 * y las de import `{m}.create` + plan, sin mirar `{m}.export`/`{m}.import`.
 */
class ImportExportPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['customers.view', 'customers.create', 'customers.export', 'customers.import'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }

    /** Crea un user del tenant 1 con un rol que tiene exactamente $perms. */
    protected function userWith(array $perms): User
    {
        $role = Role::firstOrCreate(['name' => 'role_' . Str::random(8), 'guard_name' => 'web'], ['description' => 'Test role']);
        $role->syncPermissions($perms);
        $user = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $user->assignRole($role);

        return $user;
    }

    public function test_export_csv_forbidden_without_export_permission(): void
    {
        // Puede VER pero NO tiene customers.export → debe ser 403 (antes pasaba: solo pedía .view).
        $user = $this->userWith(['customers.view']);

        $this->actingAs($user)
            ->postJson(route('business_management.customers.export_csv'))
            ->assertForbidden();
    }

    public function test_export_csv_allowed_with_export_permission(): void
    {
        // Con customers.export (+view) el gate de permiso deja pasar (no 403).
        $user = $this->userWith(['customers.view', 'customers.export']);

        $response = $this->actingAs($user)
            ->postJson(route('business_management.customers.export_csv'));

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_import_forbidden_without_import_permission(): void
    {
        // Tiene create pero NO customers.import → el gate de import debe rechazar (403).
        $user = $this->userWith(['customers.view', 'customers.create']);

        $this->actingAs($user)
            ->postJson(route('business_management.customers.import'))
            ->assertForbidden();
    }
}
