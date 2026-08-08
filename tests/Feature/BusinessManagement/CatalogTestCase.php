<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Base comun de las pruebas de los cinco catalogos de obra.
 *
 * Sedes, puestos, areas, cargos y nacionalidades son la misma pantalla con otro
 * campo, y sus pruebas montaban el mismo escenario cinco veces. Esta clase lo
 * monta una: el pais y el workspace de los que cuelga todo, el plan enterprise
 * —sin el, las rutas masivas se caen por `plan_feature` y la prueba falla por el
 * motivo equivocado— y los tres perfiles con los que se comprueba cada permiso.
 */
abstract class CatalogTestCase extends TestCase
{
    use RefreshDatabase;

    /** Clave del modulo: work_locations, positions, etc. */
    abstract protected function moduleKey(): string;

    /** Una fila ya guardada del catalogo, para las pruebas comunes. */
    abstract protected function unaFila(): \Illuminate\Database\Eloquent\Model;

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

        // Los siete permisos canonicos del modulo bajo prueba, mas los de los
        // modulos que se tocan de refilon al montar "algo que lo usa".
        foreach (['view', 'show', 'create', 'edit', 'delete', 'export', 'import'] as $accion) {
            foreach ([$this->moduleKey(), 'work_plans', 'people'] as $modulo) {
                Permission::firstOrCreate(['name' => "{$modulo}.{$accion}", 'guard_name' => 'web']);
            }
        }
    }

    /** Los campos que comparten todas las filas de catalogo. */
    protected function base(): array
    {
        return ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
    }

    /** Usuario con todos los permisos del modulo. */
    protected function admin(): User
    {
        $rol = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $rol->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /** El super: ve y gestiona tambien el catalogo global. */
    protected function makeSuper(): User
    {
        $rol = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $rol->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /** Usuario que solo puede mirar: ni crea, ni edita, ni borra. */
    protected function soloLectura(): User
    {
        $rol = Role::firstOrCreate(['name' => 'lector', 'guard_name' => 'web'], ['description' => 'Solo lectura']);
        $rol->syncPermissions(
            Permission::whereIn('name', [$this->moduleKey() . '.view', $this->moduleKey() . '.show'])->get()
        );

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /** Usuario sin ningun permiso: ni siquiera entra al listado. */
    protected function sinPermisos(): User
    {
        $rol = Role::firstOrCreate(['name' => 'pelado', 'guard_name' => 'web'], ['description' => 'Sin permisos']);
        $rol->syncPermissions([]);

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /**
     * Las seis pantallas del modulo abren sin reventar.
     *
     * Parece de perogrullo y es la que mas veces salta: una clave que el
     * controlador no manda, una ruta que la pagina nombra y no existe o una
     * relacion que llega como objeto donde el template esperaba texto no se ven
     * en las pruebas de alta y baja — solo al abrir la pantalla.
     */
    public function test_las_pantallas_del_modulo_abren(): void
    {
        $fila = $this->unaFila();
        $this->actingAs($this->makeSuper());
        $modulo = $this->moduleKey();

        foreach (['index', 'create', 'trash'] as $pantalla) {
            $this->get(route("business_management.{$modulo}.{$pantalla}"))->assertOk();
        }
        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("business_management.{$modulo}.{$pantalla}", $fila->slug))->assertOk();
        }
    }

    /**
     * Un 403 en una peticion de navegador no se ve como un 403: el manejador de
     * errores redirige al panel con un aviso. Comprobamos lo que el usuario ve
     * de verdad, no lo que Spatie lanza por dentro.
     */
    protected function assertProhibido(TestResponse $r): void
    {
        $r->assertRedirect(route('dashboard_management.dashboards.index'));
        $r->assertSessionHas('error');
    }
}
