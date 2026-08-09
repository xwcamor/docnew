<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Brand;
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
 * Un registro GLOBAL dentro de un lote no puede llevarse por delante el lote.
 *
 * Los modelos que usan `BelongsToTenantOrGlobal` tienen un guard que lanza en
 * el `updating`/`deleting`, o sea DENTRO de la transaccion y con el lote a
 * medias: la excepcion sube, se hace rollback y el usuario pierde TODO —
 * incluidas las filas que si podia tocar— terminando en un 403 que le manda al
 * dashboard sin decirle nada.
 *
 * Ya existia el tratamiento para el caso gemelo de los candados
 * (`splitLockedIds`). Esto fija el mismo comportamiento para los globales:
 * apartarlos antes de empezar y seguir con el resto.
 *
 * Se prueba sobre Marcas por ser el catalogo compartido mas simple, pero el
 * arreglo es el mismo en los once modulos que comparten el patron.
 */
class BrandGlobalRecordBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['bulk_operations' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['view', 'show', 'create', 'edit', 'delete'] as $a) {
            Permission::firstOrCreate(['name' => "brands.{$a}", 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin'])
            ->syncPermissions(Permission::all());
    }

    private function admin(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');

        return $u;
    }

    /** Marca del catalogo compartido: `tenant_id` null, solo la toca el super. */
    private function global(string $nombre): int
    {
        return DB::table('brands')->insertGetId([
            'slug' => Str::random(22), 'name' => $nombre, 'code' => Str::random(6),
            'is_active' => true, 'tenant_id' => null, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function propia(string $nombre): int
    {
        return DB::table('brands')->insertGetId([
            'slug' => Str::random(22), 'name' => $nombre, 'code' => Str::random(6),
            'is_active' => true, 'tenant_id' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_una_global_en_el_lote_no_impide_desactivar_las_demas(): void
    {
        $globalId = $this->global('MARCA GLOBAL');
        $unaId    = $this->propia('MARCA UNO');
        $dosId    = $this->propia('MARCA DOS');

        $this->actingAs($this->admin())
            ->post(route('business_management.brands.bulk_set_active'), [
                'ids'       => [$globalId, $unaId, $dosId],
                'is_active' => false,
            ]);

        $vivas = Brand::withoutGlobalScopes()->pluck('is_active', 'id');

        $this->assertFalse((bool) $vivas[$unaId], 'No se desactivo una marca que si se podia.');
        $this->assertFalse((bool) $vivas[$dosId], 'No se desactivo una marca que si se podia.');
        $this->assertTrue((bool) $vivas[$globalId], 'Se toco una marca global siendo admin.');
    }

    /** Si TODO el lote es global, se avisa en vez de fingir que se hizo algo. */
    public function test_un_lote_entero_de_globales_avisa(): void
    {
        $a = $this->global('GLOBAL A');
        $b = $this->global('GLOBAL B');

        $this->actingAs($this->admin())
            ->post(route('business_management.brands.bulk_set_active'), [
                'ids' => [$a, $b], 'is_active' => false,
            ])
            ->assertSessionHas('error');

        $vivas = Brand::withoutGlobalScopes()->pluck('is_active', 'id');
        $this->assertTrue((bool) $vivas[$a]);
        $this->assertTrue((bool) $vivas[$b]);
    }

    /** Y el super si las toca: para eso es suyo el catalogo compartido. */
    public function test_el_super_si_puede_con_las_globales(): void
    {
        $globalId = $this->global('MARCA GLOBAL');

        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super'])
            ->syncPermissions(Permission::all());
        $super = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole('super');

        $this->actingAs($super)
            ->post(route('business_management.brands.bulk_set_active'), [
                'ids' => [$globalId], 'is_active' => false,
            ]);

        $this->assertFalse(
            (bool) Brand::withoutGlobalScopes()->find($globalId)->is_active,
            'El super no pudo desactivar una global.'
        );
    }
}
