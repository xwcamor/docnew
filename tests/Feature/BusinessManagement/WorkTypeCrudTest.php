<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
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
 * La pantalla de tipos de trabajo.
 *
 * Lo que estas pruebas fijan no es que el CRUD guarde: es que el pivote
 * `work_type_form_templates` —lo que decide si un trabajo en altura puede salir
 * sin AST— no se pueda dejar en un estado que el motor de planes va a rechazar
 * despues, y que quien lo toca sepa a cuantos planes EN OBRA alcanza el cambio.
 */
class WorkTypeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([LaravelLocalizationRedirectFilter::class, LocaleSessionRedirect::class]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru',  'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Chile', 'iso_code' => 'CL', 'currency' => 'CLP', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insertOrIgnore([['id' => 1, 'slug' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 1, 'max_users' => -1, 'max_records_per_module' => -1, 'export_rate_limit' => 50, 'support_level' => 'priority', 'features' => json_encode(['team_management' => true, 'bulk_operations' => true]), 'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'USD', 'is_active' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('subscriptions')->insertOrIgnore([['id' => 1, 'tenant_id' => 1, 'plan' => 'enterprise', 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear(), 'currency' => 'USD', 'payment_method' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['view', 'show', 'create', 'edit', 'delete', 'export', 'import'] as $accion) {
            Permission::firstOrCreate(['name' => "work_types.{$accion}", 'guard_name' => 'web']);
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
        $rol->syncPermissions(Permission::whereIn('name', ['work_types.view', 'work_types.show'])->get());

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

    private function tipo(string $codigo, int $countryId = 1): WorkType
    {
        return WorkType::create(['slug' => Str::random(22), 'country_id' => $countryId,
            'tenant_id' => 1, 'created_by' => 1, 'code' => $codigo, 'is_active' => true]);
    }

    private function formato(string $codigo, string $estado = 'published', int $countryId = 1): FormTemplate
    {
        return FormTemplate::create([
            'slug' => Str::random(22), 'country_id' => $countryId, 'code' => $codigo,
            'name' => "Formato {$codigo}", 'kind' => 'structured', 'status' => $estado,
            'version' => 1, 'requires_signature' => true, 'is_active' => true,
            'tenant_id' => 1, 'created_by' => 1,
        ]);
    }

    /** Un plan de ese tipo, abierto o cerrado: es lo unico que importa aqui. */
    private function plan(WorkType $tipo, bool $cerrado): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $empresa = Company::firstOrCreate(['num_doc' => '20100000001'],
            $base + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC']);
        $sede = WorkLocation::firstOrCreate(['name' => 'Planta'], $base);

        return WorkPlan::create($base + [
            'code'             => Str::random(10),
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'description'      => 'Trabajo',
            'date_start'       => '2026-08-08',
            'is_closed'        => $cerrado,
            'is_done'          => false,
        ]);
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_un_tipo_de_trabajo_se_crea_con_su_codigo_y_su_pais(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.work_types.store'), [
            'country_id' => 1, 'code' => 'Izaje',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_types', ['country_id' => 1, 'code' => 'Izaje']);
    }

    /** El alta ya deja fijado que papeles exige: es media razon de ser del modulo. */
    public function test_al_crearlo_ya_se_pueden_fijar_los_formatos_que_exige(): void
    {
        $this->actingAs($this->admin());
        $ast = $this->formato('AST');
        $epp = $this->formato('EPP');

        $this->post(route('business_management.work_types.store'), [
            'country_id' => 1, 'code' => 'Izaje',
            'form_templates' => [
                ['id' => $ast->id, 'is_required' => true],
                ['id' => $epp->id, 'is_required' => false],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $tipo = WorkType::where('code', 'Izaje')->firstOrFail();

        $this->assertDatabaseHas('work_type_form_templates', [
            'work_type_id' => $tipo->id, 'form_template_id' => $ast->id, 'is_required' => true,
        ]);
        $this->assertDatabaseHas('work_type_form_templates', [
            'work_type_id' => $tipo->id, 'form_template_id' => $epp->id, 'is_required' => false,
        ]);
    }

    /**
     * Dos «Izaje» en el mismo pais parten en dos la configuracion de formatos:
     * la mitad de los planes acaba con un estandar y la otra mitad con otro.
     *
     * El indice unico que lo impide existe SOLO en PostgreSQL (es parcial, con
     * unaccent), asi que en cualquier otro motor esta validacion es la unica
     * defensa que hay.
     */
    public function test_no_se_repite_el_codigo_dentro_del_mismo_pais(): void
    {
        $this->actingAs($this->admin());
        $this->tipo('Izaje');

        $this->post(route('business_management.work_types.store'), [
            'country_id' => 1, 'code' => 'izaje',
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, WorkType::count());
    }

    /** Pero el mismo codigo si vale en otro pais: cada pais tiene su procedimiento. */
    public function test_el_mismo_codigo_si_vale_en_otro_pais(): void
    {
        $this->actingAs($this->admin());
        $this->tipo('Izaje');

        $this->post(route('business_management.work_types.store'), [
            'country_id' => 2, 'code' => 'Izaje',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, WorkType::count());
    }

    // ── El pivote: lo que de verdad decide este modulo ───────────────────────

    /** Marcar y desmarcar formatos desde la ficha deja el pivote exacto. */
    public function test_la_ficha_guarda_exactamente_los_formatos_marcados(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $epp  = $this->formato('EPP');
        $ptf  = $this->formato('PTF');

        $tipo->formTemplates()->sync([
            $ast->id => ['is_required' => true],
            $epp->id => ['is_required' => true],
        ]);

        // Se quita EPP, se añade PTF como opcional y AST pasa a opcional.
        $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [
                ['id' => $ast->id, 'is_required' => false],
                ['id' => $ptf->id, 'is_required' => false],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            [$ast->id => false, $ptf->id => false],
            $tipo->fresh()->formTemplates()->get()
                ->mapWithKeys(fn ($f) => [$f->id => (bool) $f->pivot->is_required])->all(),
        );
    }

    /** Vaciar la lista es una orden legitima, no un formulario a medio mandar. */
    public function test_se_pueden_quitar_todos_los_formatos_de_un_tipo(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Estandar');
        $ast  = $this->formato('AST');
        $tipo->formTemplates()->sync([$ast->id => ['is_required' => true]]);

        $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, $tipo->fresh()->formTemplates()->count());
    }

    /**
     * Un formato en borrador no se puede llenar todavia: exigirlo como
     * obligatorio bloquearia el plan contra una pantalla que revienta al abrirla.
     */
    public function test_un_formato_en_borrador_no_se_puede_exigir_como_obligatorio(): void
    {
        $this->actingAs($this->admin());
        $tipo   = $this->tipo('Izaje');
        $borrador = $this->formato('AST', 'draft');

        $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [['id' => $borrador->id, 'is_required' => true]],
        ])->assertSessionHasErrors();

        $this->assertSame(0, $tipo->fresh()->formTemplates()->count());
    }

    /** Pero si se puede proponer como opcional mientras se termina de publicar. */
    public function test_un_formato_en_borrador_si_se_puede_exigir_como_opcional(): void
    {
        $this->actingAs($this->admin());
        $tipo     = $this->tipo('Izaje');
        $borrador = $this->formato('AST', 'draft');

        $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [['id' => $borrador->id, 'is_required' => false]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $tipo->fresh()->formTemplates()->count());
    }

    /** Un formato de otro pais no se lo podria llenar nunca un plan de este tipo. */
    public function test_no_se_puede_exigir_un_formato_de_otro_pais(): void
    {
        $this->actingAs($this->admin());
        $tipo    = $this->tipo('Izaje', 1);
        $ajeno   = $this->formato('AST', 'published', 2);

        $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [['id' => $ajeno->id, 'is_required' => true]],
        ])->assertSessionHasErrors();

        $this->assertSame(0, $tipo->fresh()->formTemplates()->count());
    }

    /** La matriz ofrece TODOS los formatos del pais, no solo los ya exigidos. */
    public function test_la_ficha_ofrece_todos_los_formatos_del_pais_con_su_marca(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $this->formato('EPP');
        $this->formato('AJENO', 'published', 2);

        $tipo->formTemplates()->sync([$ast->id => ['is_required' => true]]);

        $this->get(route('business_management.work_types.show', $tipo->slug))
            ->assertOk()
            ->assertInertia(function ($page) use ($ast) {
                $matriz = collect($page->toArray()['props']['formTemplates']);

                // Los dos del pais, y ninguno del otro.
                $this->assertCount(2, $matriz);
                $this->assertNull($matriz->firstWhere('code', 'AJENO'));

                $this->assertTrue($matriz->firstWhere('id', $ast->id)['is_required']);
                $this->assertFalse($matriz->firstWhere('code', 'EPP')['is_selected']);

                return $page;
            });
    }

    // ── El aviso de planes afectados ─────────────────────────────────────────

    /**
     * El pivote se lee EN VIVO, asi que tocarlo cambia hoy mismo lo que se le
     * pide a un plan que esta en obra. Quien lo toca tiene que saber a cuantos.
     */
    public function test_la_ficha_dice_cuantos_planes_abiertos_alcanza_el_cambio(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');

        $this->plan($tipo, cerrado: false);
        $this->plan($tipo, cerrado: false);
        $this->plan($tipo, cerrado: true);

        $this->get(route('business_management.work_types.show', $tipo->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkTypes/Show')
                // Solo los abiertos: un plan cerrado es de solo lectura y su
                // documentacion ya esta firmada.
                ->where('openPlans', 2));
    }

    /** Y al guardar, el mensaje lo repite con el numero delante. */
    public function test_al_guardar_los_formatos_el_aviso_lleva_el_numero_de_planes_abiertos(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $this->plan($tipo, cerrado: false);
        $this->plan($tipo, cerrado: true);

        $r = $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [['id' => $ast->id, 'is_required' => true]],
        ]);

        $r->assertRedirect();
        $this->assertStringContainsString('1', session('success'));
    }

    /** Si el pivote no se movio, no hay nada que avisar: es un guardado normal. */
    public function test_sin_cambios_en_el_pivote_no_se_avisa_de_ningun_plan(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $tipo->formTemplates()->sync([$ast->id => ['is_required' => true]]);
        $this->plan($tipo, cerrado: false);

        $this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [['id' => $ast->id, 'is_required' => true]],
        ])->assertRedirect();

        $this->assertSame(__('work_types.saved'), session('success'));
    }

    /** La pantalla de borrado avisa de los planes abiertos antes del motivo. */
    public function test_la_pantalla_de_borrado_avisa_de_los_planes_abiertos(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $tipo->formTemplates()->sync([$ast->id => ['is_required' => true]]);
        $this->plan($tipo, cerrado: false);
        $this->plan($tipo, cerrado: true);

        $this->get(route('business_management.work_types.delete', $tipo->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkTypes/Delete')
                ->where('openPlans', 1)
                ->where('plansCount', 2)
                ->where('formsCount', 1));
    }

    // ── Edición por el formulario largo ──────────────────────────────────────

    /** El formulario largo trae la misma matriz que la ficha, ya resuelta. */
    public function test_el_formulario_de_edicion_trae_la_matriz_y_los_planes_abiertos(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $tipo->formTemplates()->sync([$ast->id => ['is_required' => true]]);
        $this->plan($tipo, cerrado: false);

        $this->get(route('business_management.work_types.edit', $tipo->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkTypes/Form')
                ->where('workType.code', 'Izaje')
                ->has('formTemplates', 1)
                ->where('formTemplates.0.is_required', true)
                ->where('openPlans', 1));
    }

    /** Cambiar el codigo y los formatos de una vez guarda las dos cosas. */
    public function test_el_formulario_largo_guarda_el_codigo_y_los_formatos_a_la_vez(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');

        $this->put(route('business_management.work_types.update', $tipo->slug), [
            'country_id' => 1, 'code' => 'Izaje critico', 'is_active' => true,
            'form_templates' => [['id' => $ast->id, 'is_required' => true]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_types', ['id' => $tipo->id, 'code' => 'Izaje critico']);
        $this->assertSame(1, $tipo->fresh()->formTemplates()->count());
    }

    /** Editar sin cambiar el codigo no choca contra su propia fila. */
    public function test_al_editar_el_codigo_no_choca_consigo_mismo(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');

        $this->put(route('business_management.work_types.update', $tipo->slug), [
            'country_id' => 1, 'code' => 'Izaje', 'is_active' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('work_types', ['id' => $tipo->id, 'is_active' => false]);
    }

    // ── Baja ─────────────────────────────────────────────────────────────────

    public function test_un_tipo_se_borra_con_motivo_y_queda_en_la_papelera(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');

        $this->delete(route('business_management.work_types.deleteSave', $tipo->slug), [
            'deleted_description' => 'el procedimiento cambio',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSoftDeleted('work_types', ['id' => $tipo->id]);
    }

    /** Un tipo borrado deja libre su codigo: el indice unico ignora los borrados. */
    public function test_el_codigo_de_un_tipo_borrado_se_puede_volver_a_usar(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $this->delete(route('business_management.work_types.deleteSave', $tipo->slug), [
            'deleted_description' => 'se rehace desde cero',
        ]);

        $this->post(route('business_management.work_types.store'), [
            'country_id' => 1, 'code' => 'Izaje',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, WorkType::count());
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_sin_permiso_de_crear_no_se_crea(): void
    {
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->get(route('business_management.work_types.create')));
        $this->assertProhibido($this->post(route('business_management.work_types.store'), [
            'country_id' => 1, 'code' => 'Izaje',
        ]));

        $this->assertSame(0, WorkType::count());
    }

    public function test_sin_permiso_de_editar_no_se_edita(): void
    {
        $tipo = $this->tipo('Izaje');
        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->put(route('business_management.work_types.update', $tipo->slug), [
            'country_id' => 1, 'code' => 'Otro',
        ]));

        $this->assertDatabaseHas('work_types', ['id' => $tipo->id, 'code' => 'Izaje']);
    }

    /**
     * El pivote es la puerta que impide sacar un formato obligatorio de un plan:
     * quien solo puede mirar no la abre.
     */
    public function test_sin_permiso_de_editar_no_se_tocan_los_formatos(): void
    {
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $tipo->formTemplates()->sync([$ast->id => ['is_required' => true]]);

        $this->actingAs($this->soloLectura());

        $this->assertProhibido($this->put(route('business_management.work_types.form_templates.update', $tipo->slug), [
            'form_templates' => [],
        ]));

        $this->assertSame(1, $tipo->fresh()->formTemplates()->count());
    }

    public function test_la_papelera_es_solo_del_super(): void
    {
        $this->assertProhibido($this->actingAs($this->admin())->get(route('business_management.work_types.trash')));
    }

    /** Y el super la ve, con lo borrado y quien lo borro. */
    public function test_el_super_ve_la_papelera_y_restaura(): void
    {
        $super = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $super->assignRole(Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Super']));
        $this->actingAs($super);

        $tipo = $this->tipo('Izaje');
        $this->delete(route('business_management.work_types.deleteSave', $tipo->slug), [
            'deleted_description' => 'se rehace desde cero',
        ]);

        $this->get(route('business_management.work_types.trash'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkTypes/Trash')
                ->has('work_types.data', 1)
                ->where('work_types.data.0.code', 'Izaje'));

        $this->post(route('business_management.work_types.restore', $tipo->slug))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('work_types', ['id' => $tipo->id, 'deleted_at' => null]);
    }

    /** El listado trae los catalogos de los selectores: sin ellos no se filtra. */
    public function test_el_listado_trae_los_catalogos_y_el_resumen_de_formatos(): void
    {
        $this->actingAs($this->admin());
        $tipo = $this->tipo('Izaje');
        $ast  = $this->formato('AST');
        $epp  = $this->formato('EPP');
        $tipo->formTemplates()->sync([
            $ast->id => ['is_required' => true],
            $epp->id => ['is_required' => false],
        ]);
        $this->plan($tipo, cerrado: false);

        $this->get(route('business_management.work_types.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WorkTypes/Index')
                ->has('options.countries', 2)
                ->has('options.form_templates', 2)
                // «3 formatos» no dice nada: lo que importa es cuantos de ellos
                // no se pueden quitar de un plan.
                ->where('work_types.data.0.forms_count', 2)
                ->where('work_types.data.0.required_forms_count', 1)
                ->where('work_types.data.0.open_plans_count', 1));
    }

    /** El filtro que hay que mirar primero: los tipos que no exigen ningun papel. */
    public function test_se_pueden_listar_los_tipos_que_no_exigen_ningun_formato(): void
    {
        $this->actingAs($this->admin());
        $conFormato = $this->tipo('Izaje');
        $this->tipo('Estandar');
        $conFormato->formTemplates()->sync([$this->formato('AST')->id => ['is_required' => true]]);

        $this->get(route('business_management.work_types.index', ['has_forms' => 'false']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('work_types.data', 1)
                ->where('work_types.data.0.code', 'Estandar'));
    }
}
