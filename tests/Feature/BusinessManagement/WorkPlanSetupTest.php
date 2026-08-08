<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\Person;
use App\Models\SignatureEvent;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkPlanApproval;
use App\Models\WorkPlanPerson;
use App\Models\WorkType;
use App\Services\BusinessManagement\WorkPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Armar el plan del dia desde su ficha: cuadrilla, formatos y aprobadores.
 *
 * Las pruebas que importan aqui no son las del camino feliz sino las tres que
 * protegen el documento de seguridad:
 *
 *   1. Nada de lo que ya se firmo o se lleno se puede quitar. Un plan es la
 *      prueba de que esa gente recibio la charla ese dia; borrar de ahi a
 *      alguien que puso la cara delante de la camara es destruir evidencia.
 *   2. Un plan cerrado, terminado o con candado no se recompone. Blindar el
 *      formulario del plan no sirve de nada si por la ficha se le puede sacar
 *      medio equipo.
 *   3. Armar el plan es del supervisor (work_plans.edit). El usuario de campo
 *      llena y firma lo que ya esta armado.
 */
class WorkPlanSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([
            'work_plans.view', 'work_plans.edit',
            'form_submissions.view', 'form_submissions.edit', 'form_submissions.sign', 'form_submissions.export',
        ] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    // ── Cuadrilla ────────────────────────────────────────────────────────────

    public function test_el_supervisor_asigna_un_trabajador_al_plan(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('Juan', 'Perez', '40000001');
        $this->actingAs($this->supervisor());

        $this->post(route('business_management.work_plans.crew.store', $plan->slug), [
            'person_slug' => $persona->slug,
        ]);

        $this->assertSame(1, $plan->people()->where('person_id', $persona->id)->count());
    }

    /** El indice unico existe, pero el usuario merece un mensaje y no un error 500. */
    public function test_la_misma_persona_no_entra_dos_veces(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('Juan', 'Perez', '40000001');
        $this->actingAs($this->supervisor());

        $ruta = route('business_management.work_plans.crew.store', $plan->slug);
        $this->post($ruta, ['person_slug' => $persona->slug]);
        $respuesta = $this->post($ruta, ['person_slug' => $persona->slug]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(1, $plan->people()->count(), 'la persona entro dos veces al mismo plan');
    }

    /**
     * LA regla del modulo: la firma es la prueba de que estuvo en obra. Ni el
     * supervisor la borra.
     */
    public function test_no_se_puede_quitar_a_quien_ya_firmo(): void
    {
        $plan = $this->plan();
        $asignado = $this->asignar($plan, $this->persona('Ana', 'Lopez', '40000002'));
        $asignado->forceFill(['is_approved' => true])->save();
        $this->actingAs($this->supervisor());

        $respuesta = $this->delete(route('business_management.work_plans.crew.destroy', [$plan->slug, $asignado->slug]));

        $respuesta->assertSessionHas('error');
        $this->assertDatabaseHas('work_plan_people', ['id' => $asignado->id]);
    }

    /**
     * Y tampoco si la firma quedo pendiente de revision: `is_approved` sigue en
     * false pero el evento —con su foto— ya existe. Mirar solo la bandera
     * dejaria borrar justo las firmas dudosas, que son las que hay que auditar.
     */
    public function test_tampoco_se_quita_a_quien_tiene_firma_pendiente_de_revision(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('Luis', 'Diaz', '40000003');
        $asignado = $this->asignar($plan, $persona);

        SignatureEvent::create([
            'signable_type' => $asignado->getMorphClass(), 'signable_id' => $asignado->id,
            'person_id' => $persona->id, 'role_signed' => 'worker',
            'signed_at' => now(), 'method' => SignatureEvent::TIMEOUT_CAPTURE,
            'pending_review' => true, 'tenant_id' => 1,
        ]);

        $this->actingAs($this->supervisor());
        $respuesta = $this->delete(route('business_management.work_plans.crew.destroy', [$plan->slug, $asignado->slug]));

        $respuesta->assertSessionHas('error');
        $this->assertDatabaseHas('work_plan_people', ['id' => $asignado->id]);
    }

    public function test_a_quien_no_firmo_si_se_le_puede_quitar(): void
    {
        $plan = $this->plan();
        $asignado = $this->asignar($plan, $this->persona('Rosa', 'Vega', '40000004'));
        $this->actingAs($this->supervisor());

        $this->delete(route('business_management.work_plans.crew.destroy', [$plan->slug, $asignado->slug]));

        $this->assertDatabaseMissing('work_plan_people', ['id' => $asignado->id]);
    }

    /** Nadie mueve la cuadrilla de un plan desde la ficha de otro. */
    public function test_no_se_quita_a_alguien_desde_la_ficha_de_otro_plan(): void
    {
        $plan = $this->plan();
        $otro = $this->plan('OTRO-1');
        $asignado = $this->asignar($plan, $this->persona('Mario', 'Cruz', '40000005'));
        $this->actingAs($this->supervisor());

        // Petición JSON a propósito: en HTML el handler global convierte el 404
        // en una redirección al dashboard y taparía el código real.
        $this->deleteJson(route('business_management.work_plans.crew.destroy', [$otro->slug, $asignado->slug]))
            ->assertNotFound();

        $this->assertDatabaseHas('work_plan_people', ['id' => $asignado->id]);
    }

    // ── Plan cerrado ─────────────────────────────────────────────────────────

    #[DataProvider('planesCerrados')]
    public function test_un_plan_cerrado_no_admite_cambios_de_cuadrilla(array $estado): void
    {
        $plan = $this->plan();
        $plan->forceFill($estado)->save();
        $persona = $this->persona('Elsa', 'Rios', '40000006');
        $this->actingAs($this->supervisor());

        $respuesta = $this->post(route('business_management.work_plans.crew.store', $plan->slug), [
            'person_slug' => $persona->slug,
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, $plan->people()->count());
    }

    public static function planesCerrados(): array
    {
        return [
            'terminado'              => [['is_done' => true]],
            'cerrado del sistema v1' => [['is_closed' => true]],
            'con candado del admin'  => [['locked_at' => '2026-01-01 00:00:00', 'lock_scope' => 'tenant']],
        ];
    }

    public function test_un_plan_terminado_tampoco_admite_cambios_de_aprobadores(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan);
        $plan->forceFill(['is_done' => true])->save();
        $this->actingAs($this->supervisor());

        $respuesta = $this->delete(route('business_management.work_plans.approvals.destroy', [$plan->slug, $aprobacion->slug]));

        $respuesta->assertSessionHas('error');
        $this->assertDatabaseHas('work_plan_approvals', ['id' => $aprobacion->id]);
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    /**
     * El usuario de campo llena y firma, pero no decide quien entra a la obra.
     * Tiene todos los permisos de `form_submissions` y aun asi no pasa.
     */
    public function test_el_usuario_de_campo_no_arma_la_cuadrilla(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('Pedro', 'Soto', '40000007');
        $this->actingAs($this->usuarioDeCampo());

        // JSON: en HTML el handler global manda el 403 al dashboard con un toast.
        $this->postJson(route('business_management.work_plans.crew.store', $plan->slug), [
            'person_slug' => $persona->slug,
        ])->assertForbidden();

        $this->assertSame(0, $plan->people()->count());
    }

    public function test_el_usuario_de_campo_no_quita_formatos_del_plan(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->usuarioDeCampo());

        $this->deleteJson(route('business_management.work_plans.forms.destroy', [$plan->slug, $this->plantilla('AST')->slug]))
            ->assertForbidden();
    }

    public function test_el_buscador_de_personas_exige_el_permiso_del_supervisor(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->usuarioDeCampo());

        $this->getJson(route('business_management.work_plans.crew.candidates', $plan->slug))
            ->assertForbidden();
    }

    // ── Formatos ─────────────────────────────────────────────────────────────

    /** El tipo de trabajo pone el estandar; el plan puede sumarle uno mas. */
    public function test_se_le_puede_anadir_un_formato_extra_a_un_plan(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $plan->workType->formTemplates()->attach($ast->id, ['is_required' => true]);
        $ihm  = $this->plantilla('IHM');
        $this->actingAs($this->supervisor());

        $this->post(route('business_management.work_plans.forms.store', $plan->slug), [
            'form_template_slug' => $ihm->slug,
        ]);

        $codigos = $plan->fresh()->expectedFormTemplates()->map(fn ($i) => $i['template']->code)->values()->all();
        $this->assertEqualsCanonicalizing(['AST', 'IHM'], $codigos);
    }

    /** Y quitarle el que no aplica, sin tocar el estandar del tipo de trabajo. */
    public function test_se_le_puede_quitar_un_formato_que_no_aplica(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $ihm  = $this->plantilla('IHM');
        $plan->workType->formTemplates()->attach([$ast->id => ['is_required' => true], $ihm->id => ['is_required' => true]]);
        $this->actingAs($this->supervisor());

        $this->delete(route('business_management.work_plans.forms.destroy', [$plan->slug, $ihm->slug]));

        $codigos = $plan->fresh()->expectedFormTemplates()->map(fn ($i) => $i['template']->code)->values()->all();
        $this->assertSame(['AST'], $codigos);

        // El estandar del tipo de trabajo NO se toca: otro plan del mismo tipo
        // sigue exigiendo los dos formatos.
        $this->assertSame(2, $plan->workType->formTemplates()->count());
    }

    /** Un formato con respuestas es el documento lleno: no se quita del plan. */
    public function test_no_se_puede_quitar_un_formato_con_respuestas(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $plan->workType->formTemplates()->attach($ast->id, ['is_required' => true]);
        $this->responder($this->entrega($plan, $ast), $ast);
        $this->actingAs($this->supervisor());

        $respuesta = $this->delete(route('business_management.work_plans.forms.destroy', [$plan->slug, $ast->slug]));

        $respuesta->assertSessionHas('error');
        $codigos = $plan->fresh()->expectedFormTemplates()->map(fn ($i) => $i['template']->code)->values()->all();
        $this->assertSame(['AST'], $codigos, 'el formato lleno desaparecio del plan');
    }

    /** Un formato confirmado tampoco, aunque no tenga respuestas cargadas. */
    public function test_no_se_puede_quitar_un_formato_confirmado(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $plan->workType->formTemplates()->attach($ast->id, ['is_required' => true]);
        $this->entrega($plan, $ast)->update(['status' => 'confirmed']);
        $this->actingAs($this->supervisor());

        $this->delete(route('business_management.work_plans.forms.destroy', [$plan->slug, $ast->slug]))
            ->assertSessionHas('error');
    }

    /** La pantalla de obra muestra lo mismo que la ficha, no el estandar a secas. */
    public function test_la_pantalla_de_obra_respeta_los_formatos_del_plan(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $ihm  = $this->plantilla('IHM');
        $plan->workType->formTemplates()->attach([$ast->id => ['is_required' => true], $ihm->id => ['is_required' => true]]);
        $supervisor = $this->supervisor();
        $this->actingAs($supervisor);

        $this->delete(route('business_management.work_plans.forms.destroy', [$plan->slug, $ihm->slug]));

        $this->get(route('field_work.forms.index', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->component('FieldWork/Forms')
                ->has('templates', 1)
                ->where('templates.0.code', 'AST'));
    }

    // ── Aprobaciones ─────────────────────────────────────────────────────────

    /**
     * Las reglas del pais proponen los aprobadores al crear el plan: es lo que
     * impide que un plan salga a obra sin firma de HSE porque nadie se acordo
     * de pedirla.
     */
    public function test_al_crear_un_plan_las_reglas_proponen_los_aprobadores(): void
    {
        $this->regla('supervisor', 1, true);
        $this->regla('hse_supervisor', 2, false);
        $usuario = $this->supervisor();
        $this->actingAs($usuario);

        $plan = app(WorkPlanService::class)->create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1,
            'company_id' => $this->empresa()->id,
            'work_type_id' => $this->tipoDeTrabajo()->id,
            'work_location_id' => $this->sede()->id,
            'user_id' => $usuario->id,
            'description' => 'Trabajo', 'date_start' => '2026-08-08',
        ]);

        $this->assertSame(2, $plan->approvals()->count());
        // Sin persona asignada: quien firma como supervisor cambia cada dia.
        $this->assertNull($plan->approvals()->first()->person_id);
        $this->assertSame(1, $plan->approvals()->where('is_required', true)->count());
    }

    public function test_el_supervisor_le_pone_nombre_a_un_aprobador(): void
    {
        $plan = $this->plan();
        $regla = $this->regla('supervisor', 1, true);
        $persona = $this->persona('Carlos', 'Mena', '40000008');
        $this->actingAs($this->supervisor());

        $this->post(route('business_management.work_plans.approvals.store', $plan->slug), [
            'approval_rule_id' => $regla->id,
            'person_slug'      => $persona->slug,
            'is_required'      => true,
        ]);

        $aprobacion = $plan->approvals()->first();
        $this->assertNotNull($aprobacion);
        $this->assertSame($persona->id, $aprobacion->person_id);
        $this->assertTrue($aprobacion->is_required);
    }

    public function test_no_se_puede_quitar_un_aprobador_que_ya_firmo(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan);
        $aprobacion->forceFill(['is_approved' => true])->save();
        $this->actingAs($this->supervisor());

        $respuesta = $this->delete(route('business_management.work_plans.approvals.destroy', [$plan->slug, $aprobacion->slug]));

        $respuesta->assertSessionHas('error');
        $this->assertDatabaseHas('work_plan_approvals', ['id' => $aprobacion->id]);
    }

    /**
     * La misma regla que protege al trabajador, aplicada al aprobador: una firma
     * de aprobacion es evidencia igual que la otra.
     *
     * Este es el caso que faltaba cubrir. Si el reconocimiento no cerro y la
     * firma quedo pendiente de revision, `is_approved` sigue en false pero el
     * evento —con su foto— ya existe; mirar solo la bandera dejaria borrar justo
     * las aprobaciones dudosas, que son las que hay que poder auditar.
     */
    public function test_tampoco_se_quita_un_aprobador_con_firma_pendiente_de_revision(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('Marta', 'Quispe', '40000012');
        $aprobacion = $this->aprobacion($plan);
        $aprobacion->forceFill(['person_id' => $persona->id])->save();

        SignatureEvent::create([
            'signable_type' => $aprobacion->getMorphClass(), 'signable_id' => $aprobacion->id,
            'person_id' => $persona->id, 'role_signed' => 'supervisor',
            'signed_at' => now(), 'method' => SignatureEvent::TIMEOUT_CAPTURE,
            'pending_review' => true, 'tenant_id' => 1,
        ]);

        $this->actingAs($this->supervisor());
        $respuesta = $this->delete(route('business_management.work_plans.approvals.destroy', [$plan->slug, $aprobacion->slug]));

        $respuesta->assertSessionHas('error');
        $this->assertDatabaseHas('work_plan_approvals', ['id' => $aprobacion->id]);
    }

    /** Y el que no firmo si se quita: la proteccion no puede congelar el armado. */
    public function test_un_aprobador_que_no_firmo_si_se_puede_quitar(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan);
        $this->actingAs($this->supervisor());

        $this->delete(route('business_management.work_plans.approvals.destroy', [$plan->slug, $aprobacion->slug]));

        $this->assertDatabaseMissing('work_plan_approvals', ['id' => $aprobacion->id]);
    }

    // ── La ficha ─────────────────────────────────────────────────────────────

    /** La ficha trae la cuadrilla, los formatos y las aprobaciones resueltos. */
    public function test_la_ficha_del_plan_muestra_cuadrilla_formatos_y_aprobaciones(): void
    {
        $plan = $this->plan();
        $ast = $this->plantilla('AST');
        $plan->workType->formTemplates()->attach($ast->id, ['is_required' => true]);
        $this->asignar($plan, $this->persona('Nora', 'Paz', '40000009'));
        $this->aprobacion($plan);
        $this->actingAs($this->supervisor());

        $this->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->component('WorkPlans/Show')
                ->has('crew', 1)
                ->where('crew.0.name', 'Nora Paz')
                ->where('crew.0.signed', false)
                ->has('forms', 1)
                ->where('forms.0.code', 'AST')
                ->where('forms.0.status', 'pending')
                ->has('approvals', 1)
                ->where('setup.can', true));
    }

    /** Y en un plan cerrado dice por que no se puede armar, en vez de callarse. */
    public function test_la_ficha_de_un_plan_cerrado_explica_por_que_no_se_edita(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['is_closed' => true])->save();
        $this->actingAs($this->supervisor());

        $this->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                ->where('setup.can', false)
                ->where('setup.reason', __('work_plans.setup_blocked_closed')));
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function base(): array
    {
        return ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];
    }

    private function supervisor(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['work_plans.view', 'work_plans.edit', 'form_submissions.view', 'form_submissions.export']);

        return $u;
    }

    private function usuarioDeCampo(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['work_plans.view', 'form_submissions.view', 'form_submissions.edit', 'form_submissions.sign']);

        return $u;
    }

    private function empresa(): Company
    {
        return Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
    }

    private function tipoDeTrabajo(): WorkType
    {
        return WorkType::firstOrCreate(['code' => 'MTTO'], $this->base());
    }

    private function sede(): WorkLocation
    {
        return WorkLocation::firstOrCreate(['name' => 'Planta'], $this->base());
    }

    private function plan(string $codigo = 'PE26-0808-0001'): WorkPlan
    {
        return WorkPlan::create($this->base() + [
            'company_id'       => $this->empresa()->id,
            'work_type_id'     => $this->tipoDeTrabajo()->id,
            'work_location_id' => $this->sede()->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => $codigo, 'description' => 'Trabajo', 'date_start' => '2026-08-08',
        ]);
    }

    private function persona(string $nombre, string $apellido, string $doc): Person
    {
        return Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => $doc,
            'name' => $nombre, 'lastname' => $apellido, 'is_active' => true,
        ]);
    }

    private function asignar(WorkPlan $plan, Person $persona): WorkPlanPerson
    {
        return $plan->people()->create(['slug' => Str::random(22), 'person_id' => $persona->id]);
    }

    private function plantilla(string $codigo): FormTemplate
    {
        return FormTemplate::firstOrCreate(['code' => $codigo], $this->base() + [
            'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => 1, 'requires_signature' => false, 'published_at' => now(),
        ]);
    }

    private function entrega(WorkPlan $plan, FormTemplate $plantilla): FormSubmission
    {
        return FormSubmission::create($this->base() + [
            'work_plan_id' => $plan->id, 'form_template_id' => $plantilla->id,
            'template_version' => 1, 'status' => 'draft',
        ]);
    }

    /** Deja el formato con una respuesta cargada: eso lo vuelve intocable. */
    private function responder(FormSubmission $entrega, FormTemplate $plantilla): void
    {
        $seccion = FormSection::create([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'code' => 'general', 'title' => 'General', 'position' => 1,
        ]);
        $campo = FormField::create([
            'slug' => Str::random(22), 'form_section_id' => $seccion->id,
            'code' => 'actividad', 'label' => 'Actividad', 'field_type' => 'text',
            'is_required' => true, 'position' => 1,
        ]);
        FormAnswer::create([
            'form_submission_id' => $entrega->id, 'form_field_id' => $campo->id,
            'row_index' => 0, 'value_text' => 'Cambio de aisladores',
        ]);
    }

    private function regla(string $rol, int $prioridad, bool $obligatoria): ApprovalRule
    {
        return ApprovalRule::create($this->base() + [
            'approver_role' => $rol, 'priority_level' => $prioridad,
            'is_required' => $obligatoria, 'is_active' => true,
        ]);
    }

    private function aprobacion(WorkPlan $plan): WorkPlanApproval
    {
        return $plan->approvals()->create([
            'slug'             => Str::random(22),
            'approval_rule_id' => $this->regla('supervisor', 1, true)->id,
            'person_id'        => null,
            'is_required'      => true,
        ]);
    }
}
