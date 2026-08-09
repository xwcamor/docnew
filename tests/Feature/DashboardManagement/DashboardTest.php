<?php

namespace Tests\Feature\DashboardManagement;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
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
 * El panel es la primera pantalla despues de entrar, asi que lo que enseñe
 * tiene que ser cierto. Estas pruebas vienen de tres cosas que fallaron:
 *
 *   1. La rama del usuario que no es super estaba **vacia**: el supervisor
 *      entraba y solo veia el saludo. El controlador mandaba `fieldWork` y la
 *      pantalla esperaba `fleet`, asi que ni con la rama escrita habria pintado
 *      nada.
 *   2. Los conteos de firmas y formatos salian de tablas **sin scope de
 *      workspace**: un tenant veia las firmas pendientes de los demas.
 *   3. «Hoy» se calculaba en UTC sobre una columna que guarda la hora de la
 *      obra: en Lima el panel cambiaba de dia a las 19:00.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            LaravelLocalizationRedirectFilter::class,
            LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([
            ['id' => 1, 'slug' => Str::random(22), 'name' => 'Contratista Uno', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'slug' => Str::random(22), 'name' => 'Contratista Dos', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['work_plans.view', 'work_plans.create', 'signature_events.review'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
        Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 's']);
    }

    // ── La pantalla abre ─────────────────────────────────────────────────────

    /** La prueba que no existia: que el panel pinte algo para quien no es super. */
    public function test_el_panel_abre_y_trae_el_dia_para_un_supervisor(): void
    {
        $this->actingAs($this->supervisor());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Index')
                ->where('isSuper', false)
                ->has('today.widgets')
                ->has('today.plans'));
    }

    /** Y para el super, que ademas lleva el estado de la plataforma. */
    public function test_el_panel_abre_para_el_super_con_los_dos_bloques(): void
    {
        $this->actingAs($this->super());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('isSuper', true)
                ->has('today.widgets')
                ->has('widgets', 4));
    }

    // ── Lo que enseña es cierto ──────────────────────────────────────────────

    /** «Planes de hoy» son los de hoy, no todos los del historico. */
    public function test_solo_cuenta_como_de_hoy_el_plan_con_fecha_de_hoy(): void
    {
        $this->plan('PE26-HOY-0001', now()->format('Y-m-d H:i'));
        $this->plan('PE26-AYER-001', now()->subDay()->format('Y-m-d H:i'));

        $this->actingAs($this->supervisor());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(fn ($page) => $page
                ->where('today.plansTotal', 1)
                ->where('today.widgets.0.key', 'plans_today')
                ->where('today.widgets.0.value', 1));
    }

    /**
     * El fallo de aislamiento: `work_plan_approvals` y `form_submissions` no
     * tienen scope de workspace, asi que contarlas sueltas mezclaba tenants.
     */
    public function test_los_conteos_no_se_salen_del_workspace(): void
    {
        // Mio: una aprobacion obligatoria sin firmar y un formato en borrador.
        $mio = $this->plan('PE26-MIO-0001', now()->format('Y-m-d H:i'));
        $this->aprobacionPendiente($mio);
        $this->entregaEnBorrador($mio);

        // Del vecino: lo mismo, y no me tiene que llegar nada de esto.
        $ajeno = $this->plan('PE26-OTRO-001', now()->format('Y-m-d H:i'), tenantId: 2);
        $this->aprobacionPendiente($ajeno);
        $this->entregaEnBorrador($ajeno);

        $this->actingAs($this->supervisor());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(fn ($page) => $page
                ->where('today.plansTotal', 1)
                ->where('today.widgets.1.key', 'signatures_pending')
                ->where('today.widgets.1.value', 1)
                ->where('today.widgets.2.key', 'forms_pending')
                ->where('today.widgets.2.value', 1));
    }

    /** Un formato confirmado ya no cuenta como pendiente. */
    public function test_el_formato_confirmado_deja_de_estar_pendiente(): void
    {
        $plan = $this->plan('PE26-FORM-001', now()->format('Y-m-d H:i'));
        $this->entregaEnBorrador($plan)->update(['status' => 'confirmed']);

        $this->actingAs($this->supervisor());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(fn ($page) => $page->where('today.widgets.2.value', 0));
    }

    /** Cada plan del listado dice que le falta, con las palabras de la ficha. */
    public function test_cada_plan_de_hoy_dice_que_le_falta(): void
    {
        $plan = $this->plan('PE26-FALTA-01', now()->format('Y-m-d H:i'));
        $this->aprobacionPendiente($plan);

        $this->actingAs($this->supervisor());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(function ($page) {
                $planes = $page->toArray()['props']['today']['plans'];

                $this->assertCount(1, $planes);
                $this->assertSame('PE26-FALTA-01', $planes[0]['code']);
                $this->assertNotEmpty($planes[0]['missing'], 'el plan no dice que le falta');
            });
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    /**
     * Sin `work_plans.view` no se enseña el panel del dia. Un cuadro de ceros
     * sobre datos que no puede abrir es peor que no tenerlo.
     */
    public function test_sin_permiso_de_planes_no_hay_panel_del_dia(): void
    {
        $this->plan('PE26-HOY-0002', now()->format('Y-m-d H:i'));

        $usuario = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $this->actingAs($usuario);

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('today', null));
    }

    /** La bandeja de firmas dudosas solo aparece a quien la puede resolver. */
    public function test_la_bandeja_de_revision_solo_sale_con_su_permiso(): void
    {
        $sinPermiso = $this->supervisor();
        $this->actingAs($sinPermiso);
        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(fn ($page) => $page->has('today.widgets', 4));

        $conPermiso = $this->supervisor();
        $conPermiso->givePermissionTo('signature_events.review');
        $this->actingAs($conPermiso);
        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(fn ($page) => $page
                ->has('today.widgets', 5)
                ->where('today.widgets.4.key', 'signatures_review'));
    }

    /** El enlace a Usuarios no se manda a quien no puede abrir Usuarios. */
    public function test_el_widget_del_workspace_no_enlaza_a_donde_no_se_puede_entrar(): void
    {
        $this->actingAs($this->supervisor());

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(function ($page) {
                $widgets = collect($page->toArray()['props']['workspaceWidgets']);
                $usuarios = $widgets->firstWhere('key', 'users_count');

                $this->assertNotNull($usuarios, 'falta el widget de usuarios del workspace');
                $this->assertNull($usuarios['href'], 'enlaza a Usuarios sin permiso para verlo');
            });
    }

    // ── Las etiquetas del panel existen en los dos idiomas ───────────────────

    /**
     * La pantalla arma las claves de los indicadores concatenando
     * `dashboard.widget_` + la key que manda el servidor. Si el servidor
     * inventa una key sin traduccion, el usuario ve `DASHBOARD.WIDGET_X`.
     */
    public function test_todo_indicador_tiene_su_etiqueta_traducida(): void
    {
        $usuario = $this->super();
        $usuario->givePermissionTo('signature_events.review');
        $this->actingAs($usuario);

        $this->get(route('dashboard_management.dashboards.index'))
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                $keys = collect($props['today']['widgets'])
                    ->merge($props['widgets'])
                    ->merge($props['workspaceWidgets'])
                    ->pluck('key');

                foreach (['es', 'en'] as $idioma) {
                    $textos = require lang_path("{$idioma}/dashboard.php");

                    foreach ($keys as $key) {
                        $this->assertArrayHasKey("widget_{$key}", $textos,
                            "falta dashboard.widget_{$key} en {$idioma}");
                    }
                }
            });
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function base(int $tenantId = 1): array
    {
        return ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => $tenantId, 'created_by' => 1];
    }

    protected function supervisor(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['work_plans.view', 'work_plans.create']);

        return $u;
    }

    private function super(): User
    {
        $u = User::factory()->create(['tenant_id' => null, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('super');

        return $u;
    }

    protected function plan(string $codigo, string $inicio, int $tenantId = 1): WorkPlan
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '2010000000' . $tenantId],
            $this->base($tenantId) + ['name' => "Contratista {$tenantId}", 'complete_name' => "Contratista {$tenantId} SAC"],
        );
        $tipo = WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());
        $sede = WorkLocation::firstOrCreate(['name' => 'Planta'], $this->base());

        // El scope de WorkPlan fuerza el tenant del usuario autenticado al
        // crear; aqui se crea sin sesion y se fija el tenant a mano.
        return WorkPlan::create($this->base($tenantId) + [
            'company_id'       => $empresa->id,
            'work_type_id'     => $tipo->id,
            'work_location_id' => $sede->id,
            'user_id'          => User::factory()->create(['tenant_id' => $tenantId, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => $codigo,
            'description'      => 'Trabajo',
            'date_start'       => $inicio,
        ]);
    }

    private function aprobacionPendiente(WorkPlan $plan): WorkPlanApproval
    {
        $regla = ApprovalRule::firstOrCreate(
            ['approver_role' => 'supervisor', 'priority_level' => 1],
            $this->base() + ['is_required' => true, 'is_active' => true],
        );

        return WorkPlanApproval::create([
            'slug'             => Str::random(22),
            'work_plan_id'     => $plan->id,
            'approval_rule_id' => $regla->id,
            'person_id'        => $this->persona()->id,
            'is_required'      => true,
            'is_approved'      => false,
        ]);
    }

    private function entregaEnBorrador(WorkPlan $plan): FormSubmission
    {
        $plantilla = FormTemplate::firstOrCreate(['code' => 'AST'], $this->base() + [
            'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => 1, 'requires_signature' => false, 'published_at' => now(),
        ]);

        return FormSubmission::create($this->base($plan->tenant_id) + [
            'work_plan_id'     => $plan->id,
            'form_template_id' => $plantilla->id,
            'template_version' => 1,
            'status'           => 'draft',
        ]);
    }

    private function persona(): Person
    {
        return Person::firstOrCreate(['num_doc' => '40000001'], $this->base() + [
            'doc_type' => 'DNI', 'name' => 'Ana', 'lastname' => 'Lopez', 'is_active' => true,
        ]);
    }
}
