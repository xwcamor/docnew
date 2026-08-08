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
            'work_plans.view', 'work_plans.edit', 'work_plans.create',
            'form_submissions.view', 'form_submissions.edit', 'form_submissions.sign', 'form_submissions.export',
        ] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    // ── Crear ────────────────────────────────────────────────────────────────

    /**
     * Al crear un plan se va a su ficha, no al listado.
     *
     * El plan recién creado está vacío: le faltan los trabajadores y hay que
     * decir quién firma cada aprobación, y las dos cosas se hacen en la ficha.
     * Volver al listado obligaba a buscar el plan que se acababa de crear para
     * poder seguir trabajando en él.
     */
    public function test_al_crear_un_plan_se_va_a_su_ficha(): void
    {
        // El supervisor de las demas pruebas arma planes, no los crea: aqui se
        // le añade el permiso que falta en vez de dárselo a todas.
        $usuario = $this->supervisor();
        $usuario->givePermissionTo('work_plans.create');
        $this->actingAs($usuario);

        $sede = $this->sede();

        $respuesta = $this->post(route('business_management.work_plans.store'), [
            'company_id'       => $this->empresa()->id,
            'work_type_id'     => $this->tipoDeTrabajo()->id,
            'work_location_id' => $sede->id,
            // Obligatorios, como en la v1 (`workstation_id` y `area_id` son NOT NULL).
            'workstation_id'   => $this->puestoDeTrabajo($sede)->id,
            'work_area_id'     => $this->area()->id,
            'country_id'       => 1,
            'description'      => 'Bobinado AT',
            'date_start'       => '2026-08-08 08:00',
        ]);

        $respuesta->assertSessionHasNoErrors();

        $plan = WorkPlan::latest('id')->first();

        $this->assertNotNull($plan, 'no se creo el plan');
        $respuesta->assertRedirect(route('business_management.work_plans.show', $plan->slug));
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
        $persona = $this->persona('Ines', 'Vera', '40000021');
        $plan->forceFill(['is_done' => true])->save();
        $this->actingAs($this->supervisor());

        $respuesta = $this->put(
            route('business_management.work_plans.approvals.approver', [$plan->slug, $aprobacion->slug]),
            ['person_slug' => $persona->slug],
        );

        $respuesta->assertSessionHas('error');
        $this->assertNull($aprobacion->fresh()->person_id);
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

    /**
     * Se le quita el formato **opcional** que no aplica, sin tocar el estandar.
     *
     * El tipo de trabajo marca cada formato obligatorio u opcional
     * (`work_type_documents.is_required` en la v1). El opcional es el que el
     * supervisor puede descartar en el plan del dia.
     */
    public function test_se_le_puede_quitar_un_formato_opcional_del_tipo(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $ihm  = $this->plantilla('IHM');
        $plan->workType->formTemplates()->attach([$ast->id => ['is_required' => true], $ihm->id => ['is_required' => false]]);
        $this->actingAs($this->supervisor());

        $this->delete(route('business_management.work_plans.forms.destroy', [$plan->slug, $ihm->slug]))
            ->assertSessionHas('success');

        $codigos = $plan->fresh()->expectedFormTemplates()->map(fn ($i) => $i['template']->code)->values()->all();
        $this->assertSame(['AST'], $codigos);

        // El estandar del tipo de trabajo NO se toca: otro plan del mismo tipo
        // sigue ofreciendo los dos formatos.
        $this->assertSame(2, $plan->workType->formTemplates()->count());
    }

    /**
     * Un formato **obligatorio** del tipo de trabajo no se quita del plan.
     *
     * Es la razon de ser de `work_type_documents.is_required`: el tipo decide
     * que papeles exige esa clase de maniobra, y eso es lo que impide que un
     * trabajo salga sin AST porque alguien iba con prisa. Quien crea que sobra
     * cambia el tipo de trabajo, que afecta a todos los planes y deja rastro.
     */
    public function test_no_se_quita_un_formato_obligatorio_del_tipo_de_trabajo(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $plan->workType->formTemplates()->attach($ast->id, ['is_required' => true]);
        $this->actingAs($this->supervisor());

        $this->delete(route('business_management.work_plans.forms.destroy', [$plan->slug, $ast->slug]))
            ->assertSessionHas('error');

        $codigos = $plan->fresh()->expectedFormTemplates()->map(fn ($i) => $i['template']->code)->values()->all();
        $this->assertSame(['AST'], $codigos, 'el formato obligatorio desaparecio del plan');
    }

    /**
     * Y si un plan viejo lo tenia excluido, vuelve a aparecer.
     *
     * Hay planes migrados y ajustes hechos antes de que existiera la regla. La
     * comprobacion que vale esta en `expectedFormTemplates()`, no solo en el
     * servicio: un AST excluido en su dia no puede seguir sin salir.
     */
    public function test_una_exclusion_vieja_no_esconde_un_formato_obligatorio(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $plan->workType->formTemplates()->attach($ast->id, ['is_required' => true]);

        // La exclusion que dejo la version anterior del codigo.
        $plan->formTemplateOverrides()->create([
            'slug' => Str::random(22), 'form_template_id' => $ast->id,
            'is_included' => false, 'is_required' => false,
        ]);

        $esperados = $plan->fresh()->expectedFormTemplates();

        $this->assertSame(['AST'], $esperados->map(fn ($i) => $i['template']->code)->values()->all());
        $this->assertTrue($esperados->first()['is_required']);
        $this->assertTrue($esperados->first()['from_type_required']);
    }

    /**
     * La ficha enseña **todos** los formatos publicados, con su interruptor.
     *
     * Es lo que hacía el sistema anterior: `_list_documents.html.erb` recorre el
     * catálogo entero y deshabilita el checkbox de los que el tipo de trabajo
     * exige. Aquí sólo se listaban los del plan y se «añadía» desde un
     * desplegable — con todos ya puestos el desplegable salía vacío y parecía
     * que el sistema no dejaba añadir ninguno.
     */
    public function test_la_ficha_lista_todos_los_formatos_con_su_interruptor(): void
    {
        $plan = $this->plan();
        $ast  = $this->plantilla('AST');
        $ihm  = $this->plantilla('IHM');
        $ptf  = $this->plantilla('PTF');   // publicado, pero fuera de este tipo de trabajo
        $plan->workType->formTemplates()->attach([$ast->id => ['is_required' => true], $ihm->id => ['is_required' => false]]);
        $this->actingAs($this->supervisor());

        $this->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page
                // Los tres del catálogo, no sólo los dos del plan.
                ->has('forms', 3)
                // Obligatorio del tipo: dentro y con el interruptor bloqueado.
                ->where('forms.0.code', 'AST')
                ->where('forms.0.included', true)
                ->where('forms.0.locked_by_work_type', true)
                ->where('forms.0.can_toggle', false)
                // Opcional del tipo: dentro y se puede apagar.
                ->where('forms.1.code', 'IHM')
                ->where('forms.1.included', true)
                ->where('forms.1.locked_by_work_type', false)
                ->where('forms.1.can_toggle', true)
                // Del catálogo y fuera del plan: apagado, y se puede encender.
                ->where('forms.2.code', 'PTF')
                ->where('forms.2.included', false)
                ->where('forms.2.can_toggle', true));
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
        // IHM opcional: es el que se puede descartar en el plan del dia.
        $plan->workType->formTemplates()->attach([$ast->id => ['is_required' => true], $ihm->id => ['is_required' => false]]);
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

    /**
     * Una aprobacion no se borra, y no hay ruta que lo intente.
     *
     * Pertenece al flujo, no al plan: la crea la regla del pais al nacer el
     * plan. Quitar la fila del supervisor HSE no quita la obligacion de que
     * firme, solo la esconde — y el plan pasaria por completo sin estarlo.
     *
     * Se comprueba sobre el enrutador porque la proteccion de verdad es que la
     * operacion no exista. Una comprobacion dentro del servicio se puede saltar
     * llamando al modelo; una ruta que no esta no se llama de ninguna manera.
     */
    public function test_no_existe_manera_de_borrar_una_aprobacion(): void
    {
        $rutas = collect(app('router')->getRoutes())->map(fn ($r) => $r->getName());

        $this->assertFalse($rutas->contains('business_management.work_plans.approvals.destroy'),
            'Volvio la ruta de borrar aprobaciones: pertenecen al flujo y no se borran.');

        $this->assertFalse(method_exists(\App\Services\BusinessManagement\WorkPlanSetupService::class, 'removeApproval'),
            'Volvio WorkPlanSetupService::removeApproval().');
    }

    /**
     * Una aprobacion ya firmada tampoco cambia de firmante.
     *
     * La firma es la prueba de quien se hizo responsable. Reasignarla dejaria
     * la evidencia de una persona colgando del nombre de otra.
     */
    public function test_no_se_cambia_el_firmante_de_una_aprobacion_firmada(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan);
        $aprobacion->forceFill(['is_approved' => true])->save();
        $otra = $this->persona('Luis', 'Rojas', '40000022');
        $this->actingAs($this->supervisor());

        $respuesta = $this->put(
            route('business_management.work_plans.approvals.approver', [$plan->slug, $aprobacion->slug]),
            ['person_slug' => $otra->slug],
        );

        $respuesta->assertSessionHas('error');
        $this->assertNull($aprobacion->fresh()->person_id);
    }

    /**
     * La misma regla, con la firma pendiente de revision.
     *
     * Si el reconocimiento no cerro, `is_approved` sigue en false pero el
     * evento —con su foto— ya existe. Mirar solo la bandera dejaria reasignar
     * justo las aprobaciones dudosas, que son las que hay que poder auditar.
     */
    public function test_tampoco_se_reasigna_con_firma_pendiente_de_revision(): void
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

        $otra = $this->persona('Rosa', 'Diaz', '40000023');
        $this->actingAs($this->supervisor());

        $respuesta = $this->put(
            route('business_management.work_plans.approvals.approver', [$plan->slug, $aprobacion->slug]),
            ['person_slug' => $otra->slug],
        );

        $respuesta->assertSessionHas('error');
        $this->assertSame($persona->id, $aprobacion->fresh()->person_id);
    }

    /** Una pendiente si admite firmante: la proteccion no congela el armado. */
    public function test_a_una_aprobacion_pendiente_se_le_asigna_firmante(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan);
        $persona = $this->persona('Elsa', 'Nunez', '40000024');
        $this->actingAs($this->supervisor());

        $respuesta = $this->put(
            route('business_management.work_plans.approvals.approver', [$plan->slug, $aprobacion->slug]),
            ['person_slug' => $persona->slug],
        );

        $respuesta->assertSessionHas('success');
        $this->assertSame($persona->id, $aprobacion->fresh()->person_id);
    }

    /**
     * Un supervisor HSE lo firma alguien que ES supervisor HSE.
     *
     * El sistema anterior tenía **un endpoint distinto por tipo de aprobador**
     * (`workers/select2_list`, `supervisors/…`, `hse_supervisors/…`), así que la
     * lista ya venía filtrada por rol. Aquí se ofrecía a cualquier persona
     * activa: se podía poner al ayudante a firmar como supervisor de seguridad.
     *
     * Se comprueba contra el servicio, no contra el buscador: el filtro de la
     * búsqueda es comodidad, y una petición hecha a mano no pasa por él.
     */
    public function test_no_firma_una_aprobacion_quien_no_tiene_el_rol(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan, $this->regla('hse_supervisor', 1, true));
        // Trabajador, no supervisor HSE.
        $ayudante = $this->persona('Beto', 'Cruz', '40000030', ['worker']);
        $this->actingAs($this->supervisor());

        $respuesta = $this->put(
            route('business_management.work_plans.approvals.approver', [$plan->slug, $aprobacion->slug]),
            ['person_slug' => $ayudante->slug],
        );

        $respuesta->assertSessionHas('error');
        $this->assertNull($aprobacion->fresh()->person_id);
    }

    /**
     * El ejecutante sale de los trabajadores **de este plan**, y sólo de ahí.
     *
     * Es quien está en la obra y responde por lo que se va a hacer. Ni el
     * sistema anterior ni mi primera versión lo comprobaban: los dos buscaban
     * por documento entre las 231 personas del padrón, así que se podía poner
     * como responsable de la obra a alguien que no salió a trabajar ese día.
     */
    public function test_el_ejecutante_tiene_que_estar_en_la_cuadrilla(): void
    {
        $plan = $this->plan();
        $aprobacion = $this->aprobacion($plan, $this->regla('worker', 1, true));

        // Trabajador con el rol correcto, pero que no está en este plan.
        $fuera = $this->persona('Beto', 'Cruz', '40000050', ['worker']);
        $this->actingAs($this->supervisor());

        $ruta = route('business_management.work_plans.approvals.approver', [$plan->slug, $aprobacion->slug]);

        $this->put($ruta, ['person_slug' => $fuera->slug])->assertSessionHas('error');
        $this->assertNull($aprobacion->fresh()->person_id);

        // Y en cuanto entra en la cuadrilla, sí.
        $this->asignar($plan, $fuera);

        $this->put($ruta, ['person_slug' => $fuera->slug])->assertSessionHas('success');
        $this->assertSame($fuera->id, $aprobacion->fresh()->person_id);
    }

    /** Y el buscador tampoco lo ofrece: filtra por el rol que se está asignando. */
    public function test_el_buscador_de_aprobadores_solo_devuelve_el_rol_pedido(): void
    {
        $plan = $this->plan();
        $this->persona('Beto', 'Cruz', '40000031', ['worker']);
        $this->persona('Sara', 'Lopez', '40000032', ['hse_supervisor']);
        $this->actingAs($this->supervisor());

        $ruta = route('business_management.work_plans.crew.candidates', $plan->slug);

        $this->getJson($ruta . '?role=hse_supervisor&q=40000031')
            ->assertOk()->assertJsonCount(0, 'people');

        $this->getJson($ruta . '?role=hse_supervisor&q=40000032')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.name', 'Lopez Sara');
    }

    /**
     * Primero firma el ejecutante: el flujo lo bloquea **su aprobación**, no las
     * firmas de la cuadrilla.
     *
     * Ésta es la condición literal del sistema anterior:
     *
     *     required_workers_pending = @list_plan_approvals.select { |p|
     *       p.approver_type == "Worker" && p.approval_rule.is_required && !p.is_approved }
     *
     * Yo la había puesto contra `work_plan_people` —las firmas de asistencia a
     * la charla—, que es otra cosa y no gobierna la autorización. Se comprueba
     * sobre el modelo porque es lo que mira el servidor al firmar.
     */
    public function test_el_flujo_espera_a_la_aprobacion_del_ejecutante_no_a_la_cuadrilla(): void
    {
        $plan = $this->plan();
        // Toda la cuadrilla ha firmado su asistencia...
        $this->asignar($plan, $this->persona('Nora', 'Paz', '40000040'))->update(['is_approved' => true]);

        $ejecutante = $this->aprobacion($plan, $this->regla('worker', 1, true));
        $supervisor = $this->aprobacion($plan, $this->regla('supervisor', 2, true));

        // ...y aun así el supervisor espera, porque el ejecutante no ha aprobado.
        $this->assertCount(1, $supervisor->ejecutantesPendientes());

        // El ejecutante no se espera a sí mismo.
        $this->assertCount(0, $ejecutante->ejecutantesPendientes());

        // En cuanto el ejecutante aprueba, el supervisor queda libre.
        $ejecutante->forceFill(['is_approved' => true])->save();
        $this->assertCount(0, $supervisor->refresh()->ejecutantesPendientes());
    }

    /** La misma persona no cubre dos firmas del mismo plan. */
    public function test_una_persona_no_firma_dos_roles_del_mismo_plan(): void
    {
        $plan = $this->plan();
        $persona = $this->persona('Hugo', 'Salas', '40000025');
        $primera = $this->aprobacion($plan, $this->regla('supervisor', 1, true));
        $segunda = $this->aprobacion($plan, $this->regla('hse_supervisor', 2, false));
        $primera->forceFill(['person_id' => $persona->id])->save();
        $this->actingAs($this->supervisor());

        $respuesta = $this->put(
            route('business_management.work_plans.approvals.approver', [$plan->slug, $segunda->slug]),
            ['person_slug' => $persona->slug],
        );

        $respuesta->assertSessionHas('error');
        $this->assertNull($segunda->fresh()->person_id);
    }

    // ── La ficha ─────────────────────────────────────────────────────────────

    /**
     * La ficha trae los trabajadores, los formatos y las aprobaciones resueltos.
     *
     * El nombre va con el apellido delante («Paz Nora»), como listaba a la
     * gente el sistema anterior (`Worker#str_complete_name_pro`).
     */
    public function test_la_ficha_del_plan_muestra_trabajadores_formatos_y_aprobaciones(): void
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
                ->where('crew.0.name', 'Paz Nora')
                ->where('crew.0.signed', false)
                ->has('forms', 1)
                ->where('forms.0.code', 'AST')
                ->where('forms.0.status', 'pending')
                ->has('approvals', 1)
                ->where('setup.can', true));
    }

    /**
     * El documento de un trabajador no sale entero en la ficha.
     *
     * Sin `people.view_private_info` viaja como `******09`. Y viaja **ya
     * tapado**: el JSON de Inertia se lee entero desde el navegador, asi que
     * esconderlo en la plantilla no lo esconde de nadie.
     */
    public function test_la_ficha_no_expone_el_documento_del_trabajador(): void
    {
        $plan = $this->plan();
        $this->asignar($plan, $this->persona('Nora', 'Paz', '40000009'));
        $this->actingAs($this->supervisor());

        $this->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page->where('crew.0.num_doc', '******09'))
            ->assertDontSee('40000009');
    }

    /** Y con el permiso, entero: quien tiene que ver el documento lo ve. */
    public function test_con_permiso_el_documento_sale_completo(): void
    {
        $plan = $this->plan();
        $this->asignar($plan, $this->persona('Nora', 'Paz', '40000009'));

        $usuario = $this->supervisor();
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'people.view_private_info', 'guard_name' => 'web']);
        $usuario->givePermissionTo('people.view_private_info');
        $this->actingAs($usuario);

        $this->get(route('business_management.work_plans.show', $plan->slug))
            ->assertInertia(fn ($page) => $page->where('crew.0.num_doc', '40000009'));
    }

    /**
     * El buscador de personas no es un listado.
     *
     * Antes, con la busqueda vacia, devolvia 25 personas con su documento
     * completo — y la pantalla lo llamaba sola al recibir el foco. Con menos de
     * 8 caracteres no devuelve nada, y lo que devuelve lleva el documento tapado.
     */
    public function test_el_buscador_de_personas_no_vuelca_el_padron(): void
    {
        $plan = $this->plan();
        $this->persona('Nora', 'Paz', '40000009');
        $this->persona('Hugo', 'Salas', '40000010');
        $this->actingAs($this->supervisor());

        foreach (['', '4', '4000'] as $parcial) {
            $this->getJson(route('business_management.work_plans.crew.candidates', $plan->slug) . '?q=' . $parcial)
                ->assertOk()
                ->assertJsonCount(0, 'people')
                ->assertJsonPath('partial', true);
        }

        $this->getJson(route('business_management.work_plans.crew.candidates', $plan->slug) . '?q=40000009')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.name', 'Paz Nora')
            ->assertJsonPath('people.0.num_doc', '******09');
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

    private function puestoDeTrabajo(WorkLocation $sede): \App\Models\Workstation
    {
        return \App\Models\Workstation::firstOrCreate(
            ['work_location_id' => $sede->id, 'name' => 'Celda 1'],
            ['slug' => Str::random(22), 'is_active' => true],
        );
    }

    private function area(): \App\Models\WorkArea
    {
        return \App\Models\WorkArea::firstOrCreate(['name' => 'Bobinado'], $this->base());
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

    /**
     * Una persona, con los roles que puede firmar.
     *
     * Por defecto trabajador y supervisor, que es lo que piden casi todas las
     * pruebas. Un aprobador solo se asigna si TIENE el rol —un supervisor HSE
     * lo firma un supervisor HSE—, asi que sin roles no se podria asignar a
     * nadie y la mitad de las pruebas fallaria por el motivo equivocado.
     *
     * @param  list<string>  $roles
     */
    private function persona(string $nombre, string $apellido, string $doc, array $roles = ['worker', 'supervisor']): Person
    {
        $persona = Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => $doc,
            'name' => $nombre, 'lastname' => $apellido, 'is_active' => true,
        ]);

        foreach ($roles as $rol) {
            $persona->roles()->create(['role' => $rol, 'is_active' => true]);
        }

        return $persona;
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

    private function aprobacion(WorkPlan $plan, ?ApprovalRule $regla = null): WorkPlanApproval
    {
        $regla ??= $this->regla('supervisor', 1, true);

        return $plan->approvals()->create([
            'slug'             => Str::random(22),
            'approval_rule_id' => $regla->id,
            'person_id'        => null,
            'is_required'      => (bool) $regla->is_required,
        ]);
    }
}
