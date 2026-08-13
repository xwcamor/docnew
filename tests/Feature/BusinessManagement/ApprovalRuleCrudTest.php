<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\ApproverRole;
use App\Models\User;
use App\Models\WorkType;
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
 * La pantalla de reglas del flujo.
 *
 * Lo que estas pruebas fijan no es que el CRUD guarde: es que la pantalla no
 * deje configurar cosas que el motor de aprobaciones va a rechazar despues
 * (un rol que no existe, el mismo rol firmando dos veces el mismo flujo), y
 * que la vista previa diga la verdad — que es toda su razon de ser.
 */
class ApprovalRuleCrudTest extends TestCase
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
            Permission::firstOrCreate(['name' => "approval_rules.{$accion}", 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => "approver_roles.{$accion}", 'guard_name' => 'web']);
        }
    }

    private function admin(): User
    {
        $rol = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'Test admin']);
        $rol->syncPermissions(Permission::all());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    private function soloLectura(): User
    {
        $rol = Role::firstOrCreate(['name' => 'lector', 'guard_name' => 'web'], ['description' => 'Solo lectura']);
        $rol->syncPermissions(Permission::whereIn('name', ['approval_rules.view', 'approval_rules.show'])->get());

        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        return $u;
    }

    /** Un 403 de navegador se ve como una vuelta al panel con un aviso. */
    private function assertProhibido(\Illuminate\Testing\TestResponse $r): void
    {
        $r->assertRedirect(route('dashboard_management.dashboards.index'));
        $r->assertSessionHas('error');
    }

    private function tipo(string $codigo): WorkType
    {
        return WorkType::create(['slug' => Str::random(22), 'country_id' => 1,
            'tenant_id' => 1, 'created_by' => 1, 'code' => $codigo, 'is_active' => true]);
    }

    private function super(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole(Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']));

        return $u;
    }

    private function regla(string $rol, int $nivel, bool $obligatoria = true, ?int $tipoId = null, ?string $nombre = null): ApprovalRule
    {
        return ApprovalRule::create([
            'slug' => Str::random(22), 'country_id' => 1, 'work_type_id' => $tipoId,
            'name' => $nombre,
            'approver_role' => $rol, 'priority_level' => $nivel,
            'is_required' => $obligatoria, 'is_active' => true, 'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_una_firma_mas_en_el_flujo_es_una_regla_mas(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.approval_rules.store'), [
            'country_id' => 1, 'work_type_id' => null,
            'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 2, 'is_required' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('approval_rules', [
            'country_id' => 1, 'work_type_id' => null,
            'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 2,
        ]);
    }

    /** Una regla que nombra un codigo muerto exige una firma que nadie puede dar. */
    public function test_no_se_puede_nombrar_un_rol_que_no_existe(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.approval_rules.store'), [
            'country_id' => 1, 'approver_role' => 'no_existe', 'priority_level' => 1,
        ])->assertSessionHasErrors('approver_role');

        $this->assertSame(0, ApprovalRule::count());
    }

    /** Un rol inactivo tampoco: se dejo de ofrecer a proposito. */
    public function test_no_se_puede_nombrar_un_rol_inactivo(): void
    {
        $this->actingAs($this->admin());
        ApproverRole::where('code', ApproverRole::SUPERVISOR)->update(['is_active' => false]);

        $this->post(route('business_management.approval_rules.store'), [
            'country_id' => 1, 'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 1,
        ])->assertSessionHasErrors('approver_role');
    }

    /** El mismo rol no aprueba dos veces el mismo plan. */
    public function test_el_mismo_rol_no_firma_dos_veces_el_mismo_flujo(): void
    {
        $this->actingAs($this->admin());
        $this->regla(ApproverRole::SUPERVISOR, 2);

        $this->post(route('business_management.approval_rules.store'), [
            'country_id' => 1, 'work_type_id' => null,
            'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 5,
        ])->assertSessionHasErrors('approver_role');

        $this->assertSame(1, ApprovalRule::count());
    }

    /** Pero si puede firmar en el flujo de un tipo de trabajo concreto. */
    public function test_el_mismo_rol_si_puede_firmar_en_el_flujo_de_otro_tipo(): void
    {
        $this->actingAs($this->admin());
        $this->regla(ApproverRole::SUPERVISOR, 2);
        $izaje = $this->tipo('IZAJE');

        $this->post(route('business_management.approval_rules.store'), [
            'country_id' => 1, 'work_type_id' => $izaje->id,
            'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 2,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, ApprovalRule::count());
    }

    // ── Vista previa: lo que se enseña es lo que va a pasar ──────────────────

    /**
     * El caso que motivo la pantalla: para IZAJE quedan 4 firmas y para el
     * resto 3, y la vista previa lo dice tipo a tipo antes de crear ningun plan.
     */
    public function test_la_vista_previa_enseña_el_flujo_resultante_de_cada_tipo(): void
    {
        $this->actingAs($this->admin());

        // El primer eslabon lo firmaba el rol «trabajador» —el ejecutante— y
        // ese rol ya no aprueba nada: quien responde por la gente de la obra es
        // una columna del plan, no una firma del flujo. Aqui se pone un jefe de
        // obra en su sitio, que es justo lo que la pantalla defiende: quien
        // firma es una fila del catalogo, no una constante del programa.
        ApproverRole::create(['slug' => Str::random(22), 'code' => 'site_chief',
            'name_es' => 'Jefe de obra', 'name_en' => 'Site chief', 'sort_order' => 4, 'tenant_id' => 1]);

        $this->regla('site_chief', 1);
        $this->regla(ApproverRole::SUPERVISOR, 2);
        $this->regla(ApproverRole::HSE_SUPERVISOR, 3, false);

        $izaje = $this->tipo('IZAJE');
        $mtto  = $this->tipo('MTTO');

        ApproverRole::create(['slug' => Str::random(22), 'code' => 'rigging_chief',
            'name_es' => 'Jefe de izaje', 'name_en' => 'Rigging chief', 'sort_order' => 5, 'tenant_id' => 1]);

        foreach ([['site_chief', 1], [ApproverRole::SUPERVISOR, 2],
                  [ApproverRole::HSE_SUPERVISOR, 3], ['rigging_chief', 4]] as [$rol, $nivel]) {
            $this->regla($rol, $nivel, true, $izaje->id);
        }

        $this->get(route('business_management.approval_rules.preview', ['country_id' => 1]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $flujos = collect($page->toArray()['props']['flows']);

                $izaje = $flujos->firstWhere('work_type.code', 'IZAJE');
                $mtto  = $flujos->firstWhere('work_type.code', 'MTTO');

                // A mas riesgo, mas firmas — y en el orden en que se firman.
                $this->assertCount(4, $izaje['signatures']);
                $this->assertSame('own', $izaje['source']);
                $this->assertSame([1, 2, 3, 4], array_column($izaje['signatures'], 'level'));
                $this->assertSame('rigging_chief', $izaje['signatures'][3]['role']);

                // Un tipo sin reglas propias hereda las generales del pais.
                $this->assertCount(3, $mtto['signatures']);
                $this->assertSame('inherited', $mtto['source']);

                return $page;
            });
    }

    /** Sin ninguna regla, un plan nace sin aprobaciones: eso se avisa. */
    public function test_la_vista_previa_avisa_cuando_un_tipo_se_queda_sin_firmas(): void
    {
        $this->actingAs($this->admin());
        $this->tipo('MTTO');

        $this->get(route('business_management.approval_rules.preview', ['country_id' => 1]))
            ->assertOk()
            ->assertInertia(function ($page) {
                foreach ($page->toArray()['props']['flows'] as $flujo) {
                    $this->assertSame('none', $flujo['source']);
                    $this->assertCount(0, $flujo['signatures']);
                }

                return $page;
            });
    }

    // ── Baja ─────────────────────────────────────────────────────────────────

    public function test_una_regla_se_borra_con_motivo_y_queda_en_la_papelera(): void
    {
        $this->actingAs($this->admin());
        $regla = $this->regla(ApproverRole::SUPERVISOR, 2);

        $this->delete(route('business_management.approval_rules.deleteSave', $regla->slug), [
            'deleted_description' => 'el procedimiento cambio',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('approval_rules', ['id' => $regla->id]);
    }

    /** Y deja de exigirse: la vista previa lo refleja en el acto. */
    public function test_al_borrar_una_regla_el_flujo_se_queda_con_una_firma_menos(): void
    {
        $this->actingAs($this->admin());
        $this->regla(ApproverRole::SUPERVISOR, 1);
        $hse = $this->regla(ApproverRole::HSE_SUPERVISOR, 3, false);

        $this->delete(route('business_management.approval_rules.deleteSave', $hse->slug), [
            'deleted_description' => 'ya no hace falta',
        ]);

        $this->get(route('business_management.approval_rules.preview', ['country_id' => 1]))
            ->assertOk()
            ->assertInertia(function ($page) {
                $general = $page->toArray()['props']['flows'][0];
                $this->assertCount(1, $general['signatures']);
                $this->assertSame(ApproverRole::SUPERVISOR, $general['signatures'][0]['role']);

                return $page;
            });
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.approval_rules.create')));
        $this->assertProhibido($this->post(route('business_management.approval_rules.store'), [
            'country_id' => 1, 'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 1,
        ]));

        $this->assertSame(0, ApprovalRule::count());
    }

    public function test_sin_permiso_de_editar_no_se_edita(): void
    {
        $regla = $this->regla(ApproverRole::SUPERVISOR, 2);
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->put(route('business_management.approval_rules.update', $regla->slug), [
            'country_id' => 1, 'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 9,
        ]));

        $this->assertDatabaseHas('approval_rules', ['id' => $regla->id, 'priority_level' => 2]);
    }

    /** La vista previa la ve quien puede ver el modulo, y solo ese. */
    public function test_la_vista_previa_exige_el_permiso_de_ver(): void
    {
        $this->actingAs($this->soloLectura())
            ->get(route('business_management.approval_rules.preview'))
            ->assertOk();

        $sinNada = Role::firstOrCreate(['name' => 'pelado', 'guard_name' => 'web'], ['description' => 'Sin permisos']);
        $sinNada->syncPermissions([]);
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($sinNada);

        $this->assertProhibido($this->actingAs($u)->get(route('business_management.approval_rules.preview')));
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido($this->actingAs($this->admin())->get(route('business_management.approval_rules.trash')));
    }

    // ── De quien es cada regla ───────────────────────────────────────────────

    /**
     * El listado del super dice de que empresa es cada regla.
     *
     * Las reglas SON de cada workspace —una que crea el admin de una empresa no
     * la ve ninguna otra— y el super es el unico que las ve todas mezcladas en
     * la misma tabla. Sin el workspace delante, dos reglas con el mismo nombre
     * de dos empresas distintas son indistinguibles.
     */
    public function test_el_super_ve_de_que_workspace_es_cada_regla(): void
    {
        $this->regla(ApproverRole::SUPERVISOR, 1);

        $this->actingAs($this->super())
            ->get(route('business_management.approval_rules.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('approval_rules.data.0.tenant.name', 'Empresa 1'));
    }

    /** Al admin no se le carga: todo lo que ve ya es suyo. */
    public function test_al_admin_no_se_le_manda_el_workspace(): void
    {
        $this->regla(ApproverRole::SUPERVISOR, 1);

        $this->actingAs($this->admin())
            ->get(route('business_management.approval_rules.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->missing('approval_rules.data.0.tenant'));
    }

    // ── El candado ───────────────────────────────────────────────────────────

    /**
     * Una regla bloqueada no se edita ni se borra.
     *
     * Una regla dice quien tiene que firmar un plan. Cambiarla a mitad de una
     * obra cambia lo que se le exige a lo que ya esta en marcha, y las tres que
     * vinieron de la v1 son precisamente las que llevan firmadas miles de
     * veces. De ahi que lleguen bloqueadas.
     */
    public function test_una_regla_bloqueada_no_se_edita_ni_se_borra(): void
    {
        $regla = $this->regla(ApproverRole::SUPERVISOR, 1);

        $super = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole(Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']));
        $regla->lock($super);

        $this->actingAs($this->admin());

        $this->assertProhibido($this->get(route('business_management.approval_rules.edit', $regla->slug)));
        $this->assertProhibido($this->put(route('business_management.approval_rules.update', $regla->slug), [
            'country_id' => 1, 'approver_role' => ApproverRole::HSE_SUPERVISOR, 'priority_level' => 9,
        ]));
        $this->assertProhibido($this->delete(route('business_management.approval_rules.deleteSave', $regla->slug), [
            'deleted_description' => 'ya no hace falta',
        ]));

        $regla->refresh();
        $this->assertSame(ApproverRole::SUPERVISOR, $regla->approver_role);
        $this->assertSame(1, $regla->priority_level);
        $this->assertNull($regla->deleted_at);

        // Y un admin no puede quitar un candado que puso el sistema.
        $this->assertProhibido($this->post(route('business_management.approval_rules.unlock', $regla->slug)));
        $this->assertTrue($regla->fresh()->is_locked);
    }

    /**
     * La edicion en masa era la puerta de atras del candado.
     *
     * El formulario, el borrado y las masivas ya lo hacian valer; `edit_all`
     * no, asi que las tres reglas migradas —que llegan bloqueadas— se dejaban
     * reordenar desde ahi.
     */
    public function test_la_edicion_en_masa_no_toca_una_regla_bloqueada(): void
    {
        $bloqueada = $this->regla(ApproverRole::SUPERVISOR, 2);
        $libre     = $this->regla(ApproverRole::HSE_SUPERVISOR, 1);
        $bloqueada->lock($this->super());

        $this->actingAs($this->admin());

        $this->post(route('business_management.approval_rules.edit_all.update'), [
            'changes' => [
                ['id' => $bloqueada->id, 'priority_level' => 9],
                ['id' => $libre->id,     'priority_level' => 7],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, $bloqueada->fresh()->priority_level, 'la bloqueada no se toca');
        $this->assertSame(7, $libre->fresh()->priority_level, 'la libre si');
    }

    // ── El nombre de la firma ────────────────────────────────────────────────

    /**
     * «Supervisor Autorizante - HITACHI» es el nombre de la regla; el rol
     * aprobador es el generico. La columna existia desde la migracion de la v1
     * y el formulario no la dejaba rellenar: toda regla dada de alta a mano
     * nacia sin nombre y en pantalla salia el rol.
     */
    public function test_el_nombre_de_la_firma_se_guarda_al_crear_y_al_editar(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.approval_rules.store'), [
            'name' => 'Supervisor Autorizante - HITACHI',
            'country_id' => 1, 'work_type_id' => null,
            'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 2, 'is_required' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('approval_rules', [
            'name' => 'Supervisor Autorizante - HITACHI',
            'approver_role' => ApproverRole::SUPERVISOR,
        ]);

        $regla = ApprovalRule::firstWhere('approver_role', ApproverRole::SUPERVISOR);

        $this->put(route('business_management.approval_rules.update', $regla->slug), [
            'name' => 'Supervisor de Seguridad - HITACHI',
            'country_id' => 1, 'work_type_id' => null,
            'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 2, 'is_required' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Supervisor de Seguridad - HITACHI', $regla->fresh()->name);
    }

    /** Un nombre en blanco es no tener nombre, no tener el nombre «». */
    public function test_un_nombre_en_blanco_se_guarda_como_nulo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.approval_rules.store'), [
            'name' => '   ',
            'country_id' => 1, 'approver_role' => ApproverRole::SUPERVISOR, 'priority_level' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(ApprovalRule::first()->name);
    }

    /**
     * El buscador iba solo contra el codigo del rol, asi que teclear lo que se
     * ve en la tabla no encontraba nada.
     */
    public function test_el_buscador_encuentra_por_el_nombre_de_la_firma(): void
    {
        $this->actingAs($this->admin());
        $this->regla(ApproverRole::SUPERVISOR, 2, true, null, 'Supervisor Autorizante - HITACHI');
        $this->regla(ApproverRole::HSE_SUPERVISOR, 1, true, null, 'Supervisor Ejecutante');

        $this->get(route('business_management.approval_rules.index', ['name' => 'HITACHI']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $filas = $page->toArray()['props']['approval_rules']['data'];
                $this->assertCount(1, $filas);
                $this->assertSame('Supervisor Autorizante - HITACHI', $filas[0]['name']);

                return $page;
            });
    }

    /**
     * La cabecera «Rol aprobador» pedia ordenar por `approver_role_label`, que
     * no es una columna: el backend lo descartaba y pulsarla no hacia nada.
     */
    public function test_ordenar_por_rol_ordena_de_verdad(): void
    {
        $this->actingAs($this->admin());
        $this->regla(ApproverRole::SUPERVISOR, 3);
        $this->regla(ApproverRole::HSE_SUPERVISOR, 1);

        $this->get(route('business_management.approval_rules.index', ['sort' => 'approver_role', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(function ($page) {
                $filas = $page->toArray()['props']['approval_rules']['data'];
                $this->assertSame(
                    [ApproverRole::HSE_SUPERVISOR, ApproverRole::SUPERVISOR],
                    array_column($filas, 'approver_role'),
                );

                return $page;
            });
    }

    // ── Aislamiento entre workspaces ─────────────────────────────────────────

    /**
     * El listado consultaba la tabla entera: un admin de la Empresa 1 leia el
     * flujo de aprobacion de la Empresa 2. Las reglas globales (sin workspace)
     * si las ve todo el mundo — son el estandar compartido.
     */
    public function test_un_workspace_no_ve_las_reglas_de_otro(): void
    {
        DB::table('tenants')->insertOrIgnore([['id' => 2, 'slug' => Str::random(22), 'name' => 'Empresa 2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 2, 'tenant_id' => 2, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        $ajena = $this->regla(ApproverRole::SUPERVISOR, 1, true, null, 'Regla de la Empresa 2');
        $ajena->forceFill(['tenant_id' => 2])->saveQuietly();

        $global = $this->regla(ApproverRole::SUPERVISOR, 2, true, null, 'Regla de la Plataforma');
        $global->forceFill(['tenant_id' => null])->saveQuietly();

        $propia = $this->regla(ApproverRole::HSE_SUPERVISOR, 3, true, null, 'Regla de la Empresa 1');

        $this->actingAs($this->admin())
            ->get(route('business_management.approval_rules.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $nombres = array_column($page->toArray()['props']['approval_rules']['data'], 'name');
                sort($nombres);
                $this->assertSame(['Regla de la Empresa 1', 'Regla de la Plataforma'], $nombres);

                return $page;
            });

        // Y tampoco se llega a la ficha por la URL: la fila no existe para
        // este usuario, asi que el enrutador no la resuelve.
        $this->get(route('business_management.approval_rules.show', $ajena->slug))
            ->assertRedirect();
        $this->get(route('business_management.approval_rules.show', $propia->slug))
            ->assertOk();
    }

    // ── Que las pantallas abran ──────────────────────────────────────────────

    /** Ninguna de estas pantallas puede reventar: son todas las del modulo. */
    public function test_todas_las_pantallas_del_modulo_abren(): void
    {
        $regla = $this->regla(ApproverRole::SUPERVISOR, 2, true, null, 'Supervisor Autorizante - HITACHI');
        $this->tipo('IZAJE');

        $this->actingAs($this->admin());
        foreach (['index', 'create', 'preview', 'edit_all'] as $pantalla) {
            $this->get(route("business_management.approval_rules.{$pantalla}"))
                ->assertOk("la pantalla {$pantalla} no abre");
        }
        foreach (['show', 'edit', 'delete'] as $pantalla) {
            $this->get(route("business_management.approval_rules.{$pantalla}", $regla->slug))
                ->assertOk("la pantalla {$pantalla} no abre");
        }

        // La papelera es del super, con una regla dentro para que pinte filas.
        $regla->delete();
        $this->actingAs($this->super())
            ->get(route('business_management.approval_rules.trash'))->assertOk();
    }

    /** La ficha tiene que poder decir que esta bloqueada, y quien la bloqueo. */
    public function test_la_ficha_de_una_regla_bloqueada_lo_dice(): void
    {
        $regla = $this->regla(ApproverRole::SUPERVISOR, 2, true, null, 'Supervisor Autorizante - HITACHI');
        $super = $this->super();
        $regla->lock($super);

        $this->actingAs($super)
            ->get(route('business_management.approval_rules.show', $regla->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ApprovalRules/Show')
                ->where('approvalRule.name', 'Supervisor Autorizante - HITACHI')
                ->where('approvalRule.display_name', 'Supervisor Autorizante - HITACHI')
                ->where('approvalRule.lock.is_locked', true)
                ->where('approvalRule.lock.lock_scope', 'super')
                ->where('approvalRule.lock.locked_by.name', $super->name));
    }

    /** Sin nombre propio, la pantalla cae al rol y no se queda en blanco. */
    public function test_sin_nombre_propio_la_pantalla_ensena_el_rol(): void
    {
        $regla = $this->regla(ApproverRole::HSE_SUPERVISOR, 3);

        $this->actingAs($this->admin())
            ->get(route('business_management.approval_rules.show', $regla->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('approvalRule.name', null)
                ->where('approvalRule.display_name', ApproverRole::opciones()[ApproverRole::HSE_SUPERVISOR]));
    }

    /** El listado trae los catalogos de los selectores: sin ellos no se filtra. */
    public function test_el_listado_trae_los_catalogos_de_los_selectores(): void
    {
        $this->actingAs($this->admin());
        $this->tipo('IZAJE');

        $this->get(route('business_management.approval_rules.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ApprovalRules/Index')
                ->has('options.countries', 1)
                ->has('options.work_types', 1)
                ->has('options.approver_roles', 2)
                ->where('sequential', false));
    }
}
