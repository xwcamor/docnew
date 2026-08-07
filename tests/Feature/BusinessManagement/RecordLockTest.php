<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Customer;
use App\Models\OilType;
use App\Models\Transformer;
use App\Models\TransformerType;
use App\Models\User;
use Database\Seeders\DiagnosticCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lock por registro (trait Lockable). Verifica:
 *  - admin bloquea/desbloquea registros de su tenant;
 *  - un registro bloqueado no se edita/elimina/bulk/edit-all;
 *  - jerarquía de niveles: el admin NO saca un candado del super; el super sí;
 *  - el motor de diagnóstico sigue persistiendo caché aunque el trafo esté
 *    bloqueado (el lock NO frena escrituras internas).
 */
class RecordLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['bulk_operations' => true, 'edit_all' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(DiagnosticCatalogSeeder::class);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['customers.view', 'customers.show', 'customers.create', 'customers.edit', 'customers.delete',
                  'transformers.view', 'transformers.show', 'transformers.create', 'transformers.edit', 'transformers.delete',
                  'brands.view', 'brands.show', 'brands.create', 'brands.edit', 'brands.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'admin']);
        $adminRole->syncPermissions(Permission::all());
        $superRole = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'super']);
        $superRole->syncPermissions(Permission::all());

        $this->admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->admin->assignRole('admin');
        // Super sin tenant (gestiona lo global).
        $this->super = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $this->super->assignRole('super');
    }

    private function makeCustomer(): Customer
    {
        $c = new Customer();
        $c->forceFill([
            'slug' => 'cu-' . uniqid(), 'name' => 'Cliente ' . uniqid(), 'cod' => (string) random_int(1000, 9999),
            'country_id' => 1, 'is_active' => true, 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ])->save();
        return $c;
    }

    private function makeTransformer(): Transformer
    {
        $t = new Transformer();
        $t->forceFill([
            'slug' => 'tr-' . uniqid(), 'serial' => 'S' . uniqid(), 'tag' => 'TR',
            'oil_type_id' => OilType::where('code', 'mineral')->value('id'),
            'transformer_type_id' => TransformerType::where('code', 'potencia')->value('id'),
            'tenant_id' => 1, 'created_by' => $this->admin->id,
        ])->save();
        return $t;
    }

    public function test_admin_can_lock_and_unlock_own_customer(): void
    {
        $c = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->post(route('business_management.customers.lock', $c->slug))
            ->assertRedirect();
        $c->refresh();
        $this->assertTrue($c->is_locked);
        $this->assertSame('tenant', $c->lock_scope);

        $this->actingAs($this->admin)
            ->post(route('business_management.customers.unlock', $c->slug))
            ->assertRedirect();
        $this->assertFalse($c->refresh()->is_locked);
    }

    public function test_locked_customer_cannot_be_edited_or_deleted(): void
    {
        $c = $this->makeCustomer();
        $c->lock($this->admin);

        // Update (JSON → 403 real, sin el redirect amigable del handler web).
        $this->actingAs($this->admin)
            ->putJson(route('business_management.customers.update', $c->slug), [
                'name' => 'Nuevo nombre', 'cod' => $c->cod, 'country_id' => 1,
            ])->assertForbidden();
        $this->assertSame($c->name, $c->refresh()->name);

        // Borrado real (DELETE) bloqueado por el FormRequest.
        $this->actingAs($this->admin)
            ->deleteJson(route('business_management.customers.deleteSave', $c->slug), [
                'deleted_description' => 'intento de borrar un bloqueado',
            ])->assertForbidden();
        $this->assertNull($c->refresh()->deleted_at);
    }

    public function test_admin_cannot_unlock_a_super_lock_but_super_can(): void
    {
        $c = $this->makeCustomer();
        $c->lock($this->super);                 // candado de nivel 'super'
        $this->assertSame('super', $c->refresh()->lock_scope);

        // El admin NO puede sacarlo.
        $this->actingAs($this->admin)
            ->postJson(route('business_management.customers.unlock', $c->slug))
            ->assertForbidden();
        $this->assertTrue($c->refresh()->is_locked);

        // El super sí.
        $this->actingAs($this->super)
            ->post(route('business_management.customers.unlock', $c->slug))
            ->assertRedirect();
        $this->assertFalse($c->refresh()->is_locked);
    }

    public function test_bulk_delete_skips_locked_customers(): void
    {
        $locked = $this->makeCustomer();
        $free   = $this->makeCustomer();
        $locked->lock($this->admin);

        $this->actingAs($this->admin)
            ->post(route('business_management.customers.bulk_delete'), [
                'ids' => [$locked->id, $free->id],
                'deleted_description' => 'limpieza masiva de prueba',
            ])->assertRedirect();

        $this->assertNull($locked->refresh()->deleted_at, 'el bloqueado NO debe borrarse');
        $this->assertNotNull($free->refresh()->deleted_at, 'el libre sí debe borrarse');
    }

    public function test_locked_transformer_cannot_be_edited_but_engine_still_persists(): void
    {
        $t = $this->makeTransformer();
        $t->lock($this->admin);

        // Edición de ficha bloqueada.
        $this->actingAs($this->admin)
            ->putJson(route('business_management.transformers.update', $t->slug), [
                'serial' => 'CAMBIADO', 'tag' => 'X',
            ])->assertForbidden();
        $this->assertNotSame('CAMBIADO', $t->refresh()->serial);

        // El motor (save directo) SÍ puede actualizar caché aunque esté bloqueado:
        // el lock se aplica en la capa de request, no en eventos de modelo.
        $t->health_index = 77.5;
        $t->save();
        $this->assertEquals(77.5, $t->refresh()->health_index);
    }

    public function test_catalog_brand_lock_wiring_works(): void
    {
        // Smoke test del cableado de catálogos (mismo trait/concern, wiring por
        // módulo): bloquear → editar prohibido → desbloquear.
        $brand = new \App\Models\Brand();
        $brand->forceFill([
            'slug' => 'br-' . uniqid(), 'name' => 'Marca ' . uniqid(),
            'is_active' => true, 'tenant_id' => 1, 'created_by' => $this->admin->id,
        ])->save();

        $this->actingAs($this->admin)
            ->post(route('business_management.brands.lock', $brand->slug))
            ->assertRedirect();
        $this->assertTrue($brand->refresh()->is_locked);

        $this->actingAs($this->admin)
            ->putJson(route('business_management.brands.update', $brand->slug), ['name' => 'Cambiada'])
            ->assertForbidden();
        $this->assertNotSame('Cambiada', $brand->refresh()->name);

        $this->actingAs($this->admin)
            ->post(route('business_management.brands.unlock', $brand->slug))
            ->assertRedirect();
        $this->assertFalse($brand->refresh()->is_locked);
    }

    public function test_admin_cannot_lock_a_global_record(): void
    {
        // Cliente GLOBAL (tenant_id null): solo el super lo gestiona. El admin no
        // lo puede bloquear (el guard de globales lo rechazaria) → 403, sin candado.
        $global = new Customer();
        $global->forceFill([
            'slug' => 'cu-g-' . uniqid(), 'name' => 'Global ' . uniqid(), 'cod' => (string) random_int(1000, 9999),
            'country_id' => 1, 'is_active' => true, 'tenant_id' => null, 'created_by' => $this->super->id,
        ])->save();

        $this->assertFalse($global->canBeLockedBy($this->admin), 'el admin NO puede bloquear un global');
        $this->assertTrue($global->canBeLockedBy($this->super), 'el super sí');

        $this->actingAs($this->admin)
            ->postJson(route('business_management.customers.lock', $global->slug))
            ->assertForbidden();
        $this->assertFalse($global->refresh()->is_locked);
    }

    public function test_custom_profile_cannot_lock(): void
    {
        // Un perfil sin rol super/admin no alcanza las rutas de lock (role:super|admin).
        $role = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web'], ['description' => 'viewer']);
        $role->syncPermissions(['customers.view', 'customers.show']);
        $viewer = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $viewer->assignRole('viewer');

        $c = $this->makeCustomer();

        $this->actingAs($viewer)
            ->postJson(route('business_management.customers.lock', $c->slug))
            ->assertForbidden();
        $this->assertFalse($c->refresh()->is_locked);
    }
}
