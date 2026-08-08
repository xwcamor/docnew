<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
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
 * El catalogo de quien puede firmar se gestiona desde una pantalla, pero lo que
 * cuelga de el no es cosmetico: cada regla del flujo nombra un rol por su
 * codigo. Estas pruebas fijan las tres cosas que no se pueden romper desde ahi.
 */
class ApproverRoleCrudTest extends TestCase
{
    use RefreshDatabase;

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
        foreach (['view', 'show', 'create', 'edit', 'delete', 'export', 'import'] as $accion) {
            Permission::firstOrCreate(['name' => "approver_roles.{$accion}", 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => "approval_rules.{$accion}", 'guard_name' => 'web']);
        }
    }

    /** Usuario con todos los permisos del modulo. */
    private function admin(): User
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
    private function soloLectura(): User
    {
        $rol = Role::firstOrCreate(['name' => 'lector', 'guard_name' => 'web'], ['description' => 'Solo lectura']);
        $rol->syncPermissions(Permission::whereIn('name', ['approver_roles.view', 'approver_roles.show'])->get());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /**
     * Un 403 en una peticion de navegador no se ve como un 403: el manejador
     * de errores de la aplicacion redirige al panel con un aviso. Comprobamos
     * lo que el usuario ve de verdad, no lo que Spatie lanza por dentro.
     */
    private function assertProhibido(\Illuminate\Testing\TestResponse $r): void
    {
        $r->assertRedirect(route('dashboard_management.dashboards.index'));
        $r->assertSessionHas('error');
    }

    private function regla(string $rol, int $nivel = 1): ApprovalRule
    {
        return ApprovalRule::create([
            'slug' => Str::random(22), 'country_id' => 1, 'work_type_id' => null,
            'approver_role' => $rol, 'priority_level' => $nivel,
            'is_required' => true, 'is_active' => true, 'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_un_rol_nuevo_es_una_fila_y_se_da_de_alta_desde_la_pantalla(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.approver_roles.store'), [
            'code' => 'jefe_de_obra', 'name_es' => 'Jefe de obra', 'name_en' => 'Site manager',
            'sort_order' => 4,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('approver_roles', ['code' => 'jefe_de_obra', 'name_es' => 'Jefe de obra']);
    }

    /** El codigo es una clave: se normaliza en vez de rechazar lo que se teclea. */
    public function test_el_codigo_se_normaliza_a_minusculas_sin_acentos_ni_espacios(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.approver_roles.store'), [
            'code' => '  Jefe De Izaje ', 'name_es' => 'Jefe de izaje', 'name_en' => 'Rigging chief',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('approver_roles', ['code' => 'jefe_de_izaje']);
    }

    public function test_el_codigo_es_unico_dentro_del_workspace(): void
    {
        $this->actingAs($this->admin());

        ApproverRole::create(['slug' => Str::random(22), 'code' => 'cliente',
            'name_es' => 'Cliente', 'name_en' => 'Client', 'sort_order' => 4, 'tenant_id' => 1]);

        $this->post(route('business_management.approver_roles.store'), [
            'code' => 'CLIENTE', 'name_es' => 'Cliente final', 'name_en' => 'End client',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, ApproverRole::where('code', 'cliente')->count());
    }

    // ── Lo que no se puede borrar ────────────────────────────────────────────

    public function test_un_rol_usado_por_una_regla_no_se_puede_borrar(): void
    {
        $this->actingAs($this->admin());

        $rol = ApproverRole::create(['slug' => Str::random(22), 'code' => 'cliente',
            'name_es' => 'Cliente', 'name_en' => 'Client', 'sort_order' => 4, 'tenant_id' => 1]);
        $this->regla('cliente');

        $this->delete(route('business_management.approver_roles.deleteSave', $rol->slug), [
            'deleted_description' => 'ya no se usa',
        ])->assertSessionHas('error');

        $this->assertNotSoftDeleted('approver_roles', ['id' => $rol->id]);
    }

    /** Y en su lugar se le ofrece desactivarlo, que es lo que casi siempre quería. */
    public function test_a_cambio_se_puede_desactivar_y_las_reglas_siguen_en_pie(): void
    {
        $this->actingAs($this->admin());

        $rol = ApproverRole::create(['slug' => Str::random(22), 'code' => 'cliente',
            'name_es' => 'Cliente', 'name_en' => 'Client', 'sort_order' => 4, 'tenant_id' => 1]);
        $regla = $this->regla('cliente');

        $this->post(route('business_management.approver_roles.deactivate', $rol->slug))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('approver_roles', ['id' => $rol->id, 'is_active' => false]);
        $this->assertDatabaseHas('approval_rules', ['id' => $regla->id, 'is_active' => true]);
        // Y deja de ofrecerse al armar un flujo nuevo.
        $this->assertArrayNotHasKey('cliente', ApproverRole::opciones());
    }

    /**
     * Los tres del sistema no se borran ni aunque nadie los use: el motor de
     * migracion y las reglas sembradas los nombran por su codigo.
     */
    public function test_los_tres_del_sistema_no_se_borran(): void
    {
        $this->actingAs($this->admin());

        foreach ([ApproverRole::WORKER, ApproverRole::SUPERVISOR, ApproverRole::HSE_SUPERVISOR] as $codigo) {
            $rol = ApproverRole::where('code', $codigo)->sole();

            $this->assertSame(0, ApprovalRule::where('approver_role', $codigo)->count(),
                'la prueba solo vale si nadie los usa todavia');

            $this->delete(route('business_management.approver_roles.deleteSave', $rol->slug), [
                'deleted_description' => 'limpieza del catalogo',
            ])->assertSessionHas('error');

            $this->assertNotSoftDeleted('approver_roles', ['id' => $rol->id]);
        }
    }

    /**
     * El codigo de un rol del sistema tampoco cambia, aunque su nombre si. Lo
     * hace el super: los tres nacen globales y un admin de workspace no toca
     * lo que comparten todos los workspaces.
     */
    public function test_el_codigo_de_un_rol_del_sistema_no_cambia_pero_el_nombre_si(): void
    {
        $this->actingAs($this->makeSuper());
        $rol = ApproverRole::where('code', ApproverRole::HSE_SUPERVISOR)->sole();

        $this->put(route('business_management.approver_roles.update', $rol->slug), [
            'code' => 'otro_codigo', 'name_es' => 'Jefe HSE', 'name_en' => 'HSE chief',
            'sort_order' => $rol->sort_order,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('approver_roles', [
            'id' => $rol->id, 'code' => ApproverRole::HSE_SUPERVISOR, 'name_es' => 'Jefe HSE',
        ]);
    }

    /** En masa, los protegidos se saltan y se dice cuantos — no se aborta el lote. */
    public function test_el_borrado_masivo_salta_los_protegidos_y_borra_el_resto(): void
    {
        // El super, porque en el lote entra un rol global y solo el los toca.
        $this->actingAs($this->makeSuper());

        $libre = ApproverRole::create(['slug' => Str::random(22), 'code' => 'invitado',
            'name_es' => 'Invitado', 'name_en' => 'Guest', 'sort_order' => 9, 'tenant_id' => 1]);
        $sistema = ApproverRole::where('code', ApproverRole::WORKER)->sole();

        $this->post(route('business_management.approver_roles.bulk_delete'), [
            'ids' => [$libre->id, $sistema->id],
            'deleted_description' => 'limpieza del catalogo',
        ])->assertSessionHas('success');

        $this->assertSoftDeleted('approver_roles', ['id' => $libre->id]);
        $this->assertNotSoftDeleted('approver_roles', ['id' => $sistema->id]);
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.approver_roles.create')));
        $this->assertProhibido($this->post(route('business_management.approver_roles.store'), [
            'code' => 'colado', 'name_es' => 'Colado', 'name_en' => 'Sneaked',
        ]));

        $this->assertDatabaseMissing('approver_roles', ['code' => 'colado']);
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $this->actingAs($this->soloLectura());

        $rol = ApproverRole::create(['slug' => Str::random(22), 'code' => 'invitado',
            'name_es' => 'Invitado', 'name_en' => 'Guest', 'sort_order' => 9, 'tenant_id' => 1]);

        $this->assertProhibido($this->delete(route('business_management.approver_roles.deleteSave', $rol->slug), [
            'deleted_description' => 'porque si',
        ]));

        $this->assertNotSoftDeleted('approver_roles', ['id' => $rol->id]);
    }

    public function test_sin_permiso_de_ver_no_se_entra_al_listado(): void
    {
        $rolSinNada = Role::firstOrCreate(['name' => 'pelado', 'guard_name' => 'web'], ['description' => 'Sin permisos']);
        $rolSinNada->syncPermissions([]);
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rolSinNada);

        $this->assertProhibido($this->actingAs($u)->get(route('business_management.approver_roles.index')));
    }

    /** La papelera es del super, no del admin del workspace. */
    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido($this->actingAs($this->admin())->get(route('business_management.approver_roles.trash')));
    }

    public function test_el_listado_dice_cuantas_reglas_usan_cada_rol(): void
    {
        $this->actingAs($this->admin());
        $this->regla(ApproverRole::SUPERVISOR, 2);

        $this->get(route('business_management.approver_roles.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ApproverRoles/Index')
                ->where('approver_roles.data.1.code', ApproverRole::SUPERVISOR)
                ->where('approver_roles.data.1.rules_count', 1)
                ->where('approver_roles.data.0.rules_count', 0));
    }
}
