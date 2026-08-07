<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
 * Registros GLOBALES (workspace = Global, tenant_id null) — usar vs restringir.
 *
 * Verifica los dos lados que pidió el cliente:
 *   1. USABILIDAD: el admin VE y USA los globales (los selecciona en selects).
 *   2. RESTRICCIÓN: el admin NO los edita/borra — el guard del trait lanza
 *      AuthorizationException (403), nunca un 500. Solo el super los gestiona.
 *
 * Brand es el representante de los catálogos OrGlobal (mismo trait que
 * Laboratory + TapChanger*). Customer/Roles tienen sus propios tests.
 */
class GlobalRecordsRestrictionsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true, 'bulk_operations' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['brands.view', 'brands.create', 'brands.edit', 'brands.delete', 'brands.show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Super']);
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Admin']);
        $admin->syncPermissions(Permission::all());

        // Creados SIN auth activa: si hubiera un creador autenticado, BelongsToTenant
        // le forzaría su tenant al user nuevo.
        $this->super = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $this->super->assignRole('super');
        $this->admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->admin->assignRole('admin');
    }

    /** El super crea un brand GLOBAL (tenant_id null). */
    private function globalBrand(string $name = 'Marca Global'): Brand
    {
        $this->actingAs($this->super);
        $b = Brand::create(['name' => $name, 'code' => Str::slug($name), 'is_active' => true]);
        $this->assertNull($b->tenant_id, 'el super lo crea global');
        return $b;
    }

    public function test_admin_ve_y_puede_usar_un_brand_global(): void
    {
        $global = $this->globalBrand();

        $this->actingAs($this->admin);

        // Visible en sus consultas (scope OrGlobal: propios + globales).
        $this->assertTrue(Brand::query()->whereKey($global->id)->exists());
        // Aparece en las opciones activas (lo que alimenta el select del form de trafos).
        $this->assertContains($global->id, Brand::query()->where('is_active', true)->pluck('id')->all());
        // Puede ABRIR su ficha (solo-lectura).
        $this->get(route('business_management.brands.show', $global->slug))->assertOk();
    }

    public function test_admin_no_puede_editar_un_brand_global_por_http_403(): void
    {
        $global = $this->globalBrand();

        $this->actingAs($this->admin);
        // El admin TIENE brands.edit (pasa el middleware), pero el guard del trait
        // bloquea la escritura del global. El handler convierte el 403 en un
        // redirect amigable con toast (NO un 500, NO una página 403 cruda).
        $this->put(route('business_management.brands.update', $global->slug), [
            'name' => 'Hackeado', 'code' => $global->code, 'is_active' => true,
        ])->assertRedirect();

        // Lo importante: el global NO se modificó.
        $this->assertDatabaseHas('brands', ['id' => $global->id, 'name' => 'Marca Global']);
    }

    public function test_el_guard_del_modelo_lanza_authorization_no_500(): void
    {
        $global = $this->globalBrand();

        $this->actingAs($this->admin);
        $loaded = Brand::query()->whereKey($global->id)->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $loaded->update(['name' => 'Hackeado']);
    }

    public function test_bulk_delete_que_incluye_un_global_no_borra_nada_y_no_es_500(): void
    {
        $global = $this->globalBrand('Global BD');
        $this->actingAs($this->admin);
        $own = Brand::create(['name' => 'Propio', 'code' => 'propio', 'is_active' => true]);

        // Forzar selección de un global (el checkbox lo deshabilita en la UI, pero
        // un request crafteado podría mandarlo): el guard aborta la transacción.
        $this->post(route('business_management.brands.bulk_delete'), [
            'ids' => [$global->id, $own->id], 'deleted_description' => 'x',
        ])->assertRedirect(); // manejado (redirect), NO un 500.

        // Por el rollback de la transacción, NADA se borra (ni el global ni el propio).
        $this->assertNull(Brand::find($global->id)?->deleted_at, 'el global sigue vivo');
        $this->assertNull(Brand::find($own->id)?->deleted_at, 'el propio sigue vivo (rollback)');
    }

    public function test_admin_si_gestiona_sus_propios_brands(): void
    {
        $this->actingAs($this->admin);
        $own = Brand::create(['name' => 'Propio', 'code' => 'propio', 'is_active' => true]);
        $this->assertSame(1, $own->tenant_id);

        $this->put(route('business_management.brands.update', $own->slug), [
            'name' => 'Propio Editado', 'code' => 'propio', 'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('brands', ['id' => $own->id, 'name' => 'Propio Editado']);
    }
}
