<?php

namespace Tests\Feature\SystemManagement;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El super ENTRA en un workspace y trabaja dentro de el.
 *
 * Antes solo tenia dos modos, y los dos malos: veia todos los workspaces
 * mezclados —sin saber de quien era cada fila— y lo que creaba nacia sin dueño
 * (`tenant_id` null), invisible para cualquier admin. De ahi salieron las
 * empresas huerfanas que hubo que rescatar con una migracion.
 *
 * Ahora: fuera esta en la consola (ve todo, y lo que crea en los catalogos
 * compartidos es universal); dentro se comporta como el admin de ese workspace.
 */
class EntrarEnWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        foreach ([1, 2] as $id) {
            DB::table('tenants')->insertOrIgnore([['id' => $id, 'slug' => 'ws' . $id . Str::random(18), 'name' => "Workspace {$id}", 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        }
    }

    private function super(): User
    {
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'super']);
        $u = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');

        return $u;
    }

    private function empresaDe(int $tenantId, string $nombre, string $ruc): void
    {
        DB::table('companies')->insert([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => $tenantId, 'created_by' => 1,
            'name' => $nombre, 'complete_name' => $nombre . ' SAC', 'num_doc' => $ruc,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_en_la_consola_el_super_ve_todos_los_workspaces(): void
    {
        $this->empresaDe(1, 'DE UNO', '20100000001');
        $this->empresaDe(2, 'DE DOS', '20200000002');

        $this->actingAs($this->super());

        $this->assertSame(2, Company::count());
    }

    public function test_dentro_de_un_workspace_solo_ve_el_suyo(): void
    {
        $this->empresaDe(1, 'DE UNO', '20100000001');
        $this->empresaDe(2, 'DE DOS', '20200000002');

        $this->actingAs($this->super())
            ->post(route('system_management.tenants.enter', Tenant::find(2)->slug))
            ->assertRedirect();

        $this->assertSame(1, Company::count());
        $this->assertSame('DE DOS', Company::first()->name);
    }

    /** Lo que crea dentro cuelga de ese workspace, no queda huerfano. */
    public function test_lo_que_crea_dentro_es_de_ese_workspace(): void
    {
        $super = $this->super();

        $this->actingAs($super)->post(route('system_management.tenants.enter', Tenant::find(2)->slug));

        $creada = Company::create([
            'name' => 'NUEVA', 'complete_name' => 'Nueva SAC', 'num_doc' => '20300000003',
            'country_id' => 1, 'is_active' => true,
        ]);

        $this->assertSame(2, $creada->fresh()->tenant_id);
    }

    /**
     * Y desde la consola NO deja crear algo per-tenant: quedaria sin dueño,
     * invisible para todos los admins. Es el guard que evita mas huerfanos.
     */
    public function test_desde_la_consola_no_se_crea_algo_sin_dueno(): void
    {
        $this->actingAs($this->super());

        $this->expectException(\DomainException::class);

        Company::create([
            'name' => 'HUERFANA', 'complete_name' => 'Huerfana SAC', 'num_doc' => '20400000004',
            'country_id' => 1, 'is_active' => true,
        ]);
    }

    public function test_al_salir_vuelve_a_verlo_todo(): void
    {
        $this->empresaDe(1, 'DE UNO', '20100000001');
        $this->empresaDe(2, 'DE DOS', '20200000002');

        $super = $this->super();
        $this->actingAs($super)->post(route('system_management.tenants.enter', Tenant::find(2)->slug));
        $this->assertSame(1, Company::count());

        $this->actingAs($super)->post(route('system_management.tenants.leave'))->assertRedirect();

        $this->assertSame(2, Company::count());
        $this->assertNull(TenantContext::actual($super));
    }

    /**
     * Un admin no entra en ningun sitio: la puerta es solo del super.
     *
     * El grupo de rutas de Workspaces ya es solo-super y redirige antes de
     * llegar al controlador, asi que no se comprueba un 403 concreto sino lo
     * que importa: que NO se quede dentro de otro workspace.
     */
    public function test_un_admin_no_puede_entrar_en_un_workspace(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'admin']);
        $admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('system_management.tenants.enter', Tenant::find(2)->slug));

        $this->assertSame(1, TenantContext::actual($admin), 'Un admin acabo dentro de otro workspace.');
    }

    /** Y si le cuelan el id en sesion, tampoco: para el manda su columna. */
    public function test_a_un_admin_no_le_cambia_el_workspace_un_valor_en_sesion(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'admin']);
        $admin = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $admin->assignRole('admin');

        session([TenantContext::CLAVE => 2]);

        $this->assertSame(1, TenantContext::actual($admin));
    }
}
