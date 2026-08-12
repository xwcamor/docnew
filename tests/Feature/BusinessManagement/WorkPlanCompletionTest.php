<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApprovalRule;
use App\Models\Company;
use App\Models\Person;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\BusinessManagement\WorkPlanCompletionService;
use App\Services\BusinessManagement\WorkPlanService;
use App\Services\BusinessManagement\WorkPlanSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El plan se cierra solo cuando no le falta nada.
 *
 * `Plan#lock_plan_if_all_conditions_met` ponia `is_locked` e `is_done` a la vez,
 * y es la lógica que cerro **3 297 de los 3 653 planes** de los datos reales,
 * ninguno a mano. No se habia portado —aqui `is_done` solo se movia editando el
 * plan—, con lo cual el 90% se habria quedado abierto para siempre.
 *
 * La v1 miraba **dos** cosas: hora de fin y aprobaciones obligatorias. Aqui se
 * exige el plan **entero**, por decision del dueño del producto:
 *
 *   1. Hora de fin.
 *   2. Al menos un trabajador y un formato.
 *   3. Todos los trabajadores han firmado.
 *   4. Hay alguien que responde por ellos: el representante.
 *   5. Todos los formatos exigidos estan confirmados.
 *   6. Ninguna aprobacion obligatoria sin firmar.
 *
 * La cuarta no estaba escrita aparte: se colaba por la via de las aprobaciones,
 * porque habia una regla obligatoria de rol trabajador. Al sacar al
 * representante del flujo habria dejado de exigirse sin que nadie lo notara.
 *
 * Es mas estricto a proposito: un documento que va a acabar delante de un
 * inspector no se cierra a medias. Los planes migrados conservan el estado con
 * el que llegaron y no se reabren.
 *
 * Estas pruebas fijan las cinco condiciones y los tres disparadores —guardar el
 * plan, firmar y confirmar un formato—, porque un servicio que nadie llama es
 * exactamente como estaba esto antes.
 */
class WorkPlanCompletionTest extends TestCase
{
    use RefreshDatabase;

    private WorkPlanCompletionService $cierre;

    protected function setUp(): void
    {
        parent::setUp();

        // Lo minimo para que un plan exista: idioma, pais y tenant. Mismo
        // andamiaje que WorkPlanSetupTest — los seeders completos arrastran
        // medio catalogo y aqui no hace falta ninguno.
        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->cierre = app(WorkPlanCompletionService::class);
    }

    public function test_se_cierra_con_hora_de_fin_y_todas_las_obligatorias_firmadas(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertTrue($this->cierre->evaluar($plan));

        $plan->refresh();
        $this->assertTrue($plan->is_done);
        $this->assertTrue($plan->is_closed);
    }

    public function test_sin_hora_de_fin_no_se_cierra(): void
    {
        $plan = $this->plan(['date_end' => null]);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertFalse($plan->refresh()->is_done);
    }

    public function test_con_una_obligatoria_sin_firmar_no_se_cierra(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);
        $this->aprobacion($plan, obligatoria: true, firmada: false, prioridad: 2);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertFalse($plan->refresh()->is_done);
    }

    /** Una opcional sin firmar no frena nada: para eso es opcional. */
    public function test_una_opcional_sin_firmar_no_frena_el_cierre(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true,  firmada: true);
        $this->aprobacion($plan, obligatoria: false, firmada: false, prioridad: 2);

        $this->assertTrue($this->cierre->evaluar($plan));
        $this->assertTrue($plan->refresh()->is_done);
    }

    /**
     * Un formato sin confirmar frena el cierre.
     *
     * Aquí se diverge de la v1 a propósito, por decisión del dueño: allí el
     * plan se cerraba con la firma del autorizante aunque quedaran formatos en
     * borrador. Un AST en borrador no es un AST, y el documento va a acabar
     * delante de un inspector.
     */
    public function test_un_formato_sin_confirmar_frena_el_cierre(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->firmarCuadrilla($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        // El AST del plan, abierto y a medio llenar.
        $plan->submissions()->create([
            'slug' => Str::random(22),
            'form_template_id' => $plan->workType->formTemplates()->first()->id,
            'template_version' => 1, 'status' => 'draft',
        ]);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertContains(
            trans_choice('work_plans.close_needs_forms_done', 1, ['count' => 1]),
            $this->cierre->loQueFalta($plan),
        );
    }

    /** Y un trabajador sin firmar, también. */
    public function test_un_trabajador_sin_firmar_frena_el_cierre(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->confirmarFormatos($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertContains(
            trans_choice('work_plans.close_needs_signatures', 1, ['count' => 1]),
            $this->cierre->loQueFalta($plan),
        );
    }

    /**
     * Pero un plan **vacío** no se cierra.
     *
     * En la v1 esto no hacía falta escribirlo: el plan se creaba de un envío
     * con sus trabajadores y sus formatos dentro y
     * `must_have_at_least_one_document_and_worker` no dejaba guardarlo vacío.
     * Aquí el plan se arma después, así que la misma regla se comprueba en el
     * punto donde significa lo mismo: un plan vacío no llega a ser documento.
     */
    public function test_un_plan_sin_trabajadores_no_se_cierra(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00'], armado: false);
        $this->conFormato($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertContains(__('work_plans.close_needs_crew'), $this->cierre->loQueFalta($plan));
    }

    public function test_un_plan_sin_formatos_no_se_cierra(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00'], armado: false);
        $this->conCuadrilla($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertContains(__('work_plans.close_needs_forms'), $this->cierre->loQueFalta($plan));
    }

    /** Llamarlo dos veces no vuelve a cerrarlo ni cambia nada. */
    public function test_es_idempotente(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertTrue($this->cierre->evaluar($plan));
        $this->assertFalse($this->cierre->evaluar($plan->refresh()));
    }

    /** Un plan armado, sin aprobaciones y con fin se cierra: no falta nada. */
    public function test_un_plan_sin_aprobaciones_se_cierra_con_la_hora_de_fin(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);

        $this->assertTrue($this->cierre->evaluar($plan));
    }

    /**
     * El disparador real: poner la hora de fin desde el formulario.
     *
     * Es el `after_save` de la v1. Sin este enganche el servicio existiria y no
     * lo llamaria nadie, que es como estaba antes toda esta logica.
     */
    public function test_guardar_la_hora_de_fin_cierra_el_plan(): void
    {
        $plan = $this->plan(['date_end' => null]);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        app(WorkPlanService::class)->update($plan, ['date_end' => '2026-08-08 18:00:00']);

        $this->assertTrue($plan->refresh()->is_done);
        $this->assertTrue($plan->is_closed);
    }

    /** Y dice qué le falta, para poder enseñarlo en vez de callarse. */
    public function test_explica_lo_que_falta(): void
    {
        $plan = $this->plan(['date_end' => null]);
        $this->aprobacion($plan, obligatoria: true, firmada: false);
        $this->aprobacion($plan, obligatoria: true, firmada: false, prioridad: 2);

        $falta = $this->cierre->loQueFalta($plan);

        // Las cinco cosas que le faltan a este plan, en orden y sin repetir. Se
        // compara contra la traduccion, no contra un literal: la suite corre en
        // ingles y el texto en duro solo probaria en que idioma esta.
        //
        // El representante es una de ellas desde que dejo de ser una aprobacion:
        // antes se contaba dentro de «faltan N aprobaciones obligatorias» y
        // ahora se dice con su nombre, que es lo que hay que ir a hacer.
        $this->assertSame([
            __('work_plans.close_needs_date_end'),
            trans_choice('work_plans.close_needs_signatures', 1, ['count' => 1]),
            __('work_plans.close_needs_representative'),
            trans_choice('work_plans.close_needs_forms_done', 1, ['count' => 1]),
            trans_choice('work_plans.close_needs_approvals', 2, ['count' => 2]),
        ], $falta);
    }

    // ── Observaciones sin corregir ───────────────────────────────────────────

    /**
     * Una observacion sin verificar impide cerrar. Verificada, no.
     *
     * Es la regla que pidio el dueno del producto —«los formatos completados
     * SIN ADVERTENCIAS»— leida de la unica forma que funciona en obra. «Cero
     * observaciones» seria una trampa: el dia que encuentras un arnes roto ese
     * plan no cerraria nunca, ni cambiando el arnes. La v1 cerraba igual con
     * observaciones y asi se cerraron 3 297 de 3 653 planes.
     *
     * Encontrar un problema no atrapa el plan. No arreglarlo, si.
     */
    public function test_una_observacion_sin_verificar_impide_cerrar_y_verificada_no(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        [$entrega, $campo] = $this->conChecklist($plan);

        // Un trabajador con el casco no conforme y sin decir que se corrigio.
        $fila = fn (?string $verificacion) => [
            'person_name' => 'Ana Quispe',
            'items' => [['item' => 'Casco', 'answer' => 'No conforme']],
            'correction_measure' => 'Se entrega casco nuevo',
            'correction_verification' => $verificacion,
        ];

        $entrega->answers()->create([
            'slug' => Str::random(22), 'form_field_id' => $campo->id, 'row_index' => 0,
            'value_json' => $fila(null), 'tenant_id' => 1, 'created_by' => 1,
        ]);

        $this->assertContains(
            trans_choice('work_plans.close_needs_corrections', 1, ['count' => 1]),
            $this->cierre->loQueFalta($plan->refresh()),
            'una observacion sin verificar tiene que impedir el cierre',
        );
        $this->assertFalse($this->cierre->evaluar($plan));

        // Se verifica la correccion: ya se puede cerrar.
        $entrega->answers()->first()->update(['value_json' => $fila('Verificado por el supervisor')]);

        $this->assertSame([], $this->cierre->loQueFalta($plan->refresh()));
        $this->assertTrue($this->cierre->evaluar($plan));
    }

    /** Un formato conforme no pide verificar nada. */
    public function test_un_formato_conforme_no_bloquea_el_cierre(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        [$entrega, $campo] = $this->conChecklist($plan);

        $entrega->answers()->create([
            'slug' => Str::random(22), 'form_field_id' => $campo->id, 'row_index' => 0,
            'value_json' => ['person_name' => 'Ana Quispe',
                             'items' => [['item' => 'Casco', 'answer' => 'Conforme']]],
            'tenant_id' => 1, 'created_by' => 1,
        ]);

        $this->assertSame([], $this->cierre->loQueFalta($plan->refresh()));
    }

    // ── Reabrir y volver a cerrar ────────────────────────────────────────────

    /**
     * Un plan reabierto NO se vuelve a cerrar solo.
     *
     * Es la pieza que hace que «Reabrir» sirva para algo. Las condiciones se
     * siguen cumpliendo —por eso estaba cerrado—, asi que sin la suspension la
     * primera evaluacion que pase, que es el propio guardado que viene detras,
     * lo cierra otra vez: entras a corregir y te expulsa.
     */
    public function test_un_plan_reabierto_no_se_cierra_solo_aunque_no_le_falte_nada(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertTrue($this->cierre->evaluar($plan));

        $this->cierre->reabrir($plan->refresh());
        $plan->refresh();

        $this->assertFalse($plan->is_closed, 'reabrir tiene que dejar el plan en curso');
        $this->assertNotNull($plan->reopened_at);

        // Y aqui esta la clave: no le falta NADA y aun asi no se cierra.
        $this->assertSame([], $this->cierre->loQueFalta($plan));
        $this->assertFalse($this->cierre->evaluar($plan), 'el plan reabierto se cerro solo');
        $this->assertFalse($plan->refresh()->is_closed);
    }

    /** «Dar por terminado» levanta la suspension y vuelve a cerrar. */
    public function test_dar_por_terminado_vuelve_a_cerrar_el_plan(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);
        $this->cierre->evaluar($plan);
        $this->cierre->reabrir($plan->refresh());

        $this->cierre->darPorTerminado($plan->refresh());

        $plan->refresh();
        $this->assertTrue($plan->is_closed);
        $this->assertNull($plan->reopened_at, 'la marca de reabierto tiene que irse al cerrar');
    }

    /**
     * Y si todavia falta algo, no cierra y lo dice.
     *
     * La alternativa —cerrar igual porque alguien pulso el boton— seria dar por
     * bueno un documento incompleto, que es justo lo que el cierre automatico
     * existe para evitar.
     */
    public function test_dar_por_terminado_con_algo_pendiente_avisa_y_no_cierra(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($plan);
        $this->aprobacion($plan, obligatoria: true, firmada: true);
        $this->cierre->evaluar($plan);
        $this->cierre->reabrir($plan->refresh());

        // Se le quita la hora de fin: ya falta algo.
        $plan->updateQuietly(['date_end' => null]);

        try {
            $this->cierre->darPorTerminado($plan->refresh());
            $this->fail('deberia haber avisado de que falta la hora de fin');
        } catch (\DomainException $e) {
            // Contra la traduccion, no contra una palabra suelta: las pruebas
            // corren en ingles (`config/app.php`) y buscar «fin» aqui hace que
            // pase o falle segun el idioma, no segun el codigo.
            $this->assertStringContainsString(__('work_plans.close_needs_date_end'), $e->getMessage());
        }

        $this->assertFalse($plan->refresh()->is_closed);
        $this->assertNotNull($plan->refresh()->reopened_at, 'la suspension no se levanta si no cierra');
    }

    /**
     * Un campo de checklist en la plantilla del plan, para poder guardarle una
     * fila no conforme. La plantilla del fixture nace sin campos: aqui solo
     * hace falta uno, y del tipo que lleva `items`.
     *
     * @return array{0: \App\Models\FormSubmission, 1: \App\Models\FormField}
     */
    private function conChecklist(WorkPlan $plan): array
    {
        $entrega = $plan->submissions()->first();

        $seccion = \App\Models\FormSection::create([
            'slug' => Str::random(22), 'form_template_id' => $entrega->form_template_id,
            'code' => 'epp', 'position' => 1, 'tenant_id' => 1, 'created_by' => 1,
        ]);

        $campo = \App\Models\FormField::create([
            'slug' => Str::random(22), 'form_template_id' => $entrega->form_template_id,
            'form_section_id' => $seccion->id, 'code' => 'epp_por_trabajador',
            'field_type' => 'person_checklist', 'is_required' => false, 'position' => 1,
            'config' => ['items' => ['Casco'], 'answers' => ['Conforme', 'No conforme', 'No aplica']],
            'tenant_id' => 1, 'created_by' => 1,
        ]);

        return [$entrega, $campo];
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function base(): array
    {
        return ['tenant_id' => 1, 'country_id' => 1];
    }

    /**
     * Un plan armado: con su trabajador y su formato.
     *
     * Nace armado porque en la v1 no podia nacer de otra manera —
     * `must_have_at_least_one_document_and_worker` no dejaba guardarlo vacio— y
     * porque un plan vacio no se cierra. Los casos de plan a medias tienen su
     * propia prueba, donde se monta a mano lo que falta.
     */
    public function test_el_listado_puede_quedarse_solo_con_los_reabiertos(): void
    {
        $reabierto = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->completar($reabierto);
        $this->aprobacion($reabierto, obligatoria: true, firmada: true);
        $this->cierre->evaluar($reabierto);
        $this->cierre->reabrir($reabierto->refresh());

        $normal = $this->plan(['code' => 'PE26-0808-0002']);

        $ids = fn (array $params) => WorkPlan::query()
            ->filter(new \Illuminate\Http\Request($params))
            ->pluck('id')
            ->all();

        $this->assertSame([$reabierto->id], $ids(['reopened' => 1]));
        $this->assertSame([$normal->id], $ids(['reopened' => 0]));

        // Sin el filtro salen los dos: la vista «Todos» no esconde nada.
        $this->assertCount(2, $ids([]));
    }

    private function plan(array $extra = [], bool $armado = true): WorkPlan
    {
        $empresa = Company::create($this->base() + [
            'slug' => Str::random(22), 'name' => 'ACME', 'complete_name' => 'ACME S.A.',
            'num_doc' => '20100000001', 'is_active' => true,
        ]);
        $tipo = WorkType::create($this->base() + ['slug' => Str::random(22), 'code' => 'ESTANDAR', 'is_active' => true]);
        $sede = WorkLocation::create($this->base() + ['slug' => Str::random(22), 'name' => 'Lurin', 'is_active' => true]);

        $plan = WorkPlan::create($this->base() + [
            'slug' => Str::random(22), 'code' => 'PE26-0808-0001', 'description' => 'Trabajo',
            'company_id' => $empresa->id, 'work_type_id' => $tipo->id, 'work_location_id' => $sede->id,
            'user_id' => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'date_start' => '2026-08-08 08:00:00',
        ] + $extra);

        if ($armado) {
            $this->conCuadrilla($plan);
            $this->conFormato($plan);
        }

        return $plan;
    }

    private function conCuadrilla(WorkPlan $plan): void
    {
        $persona = Person::create($this->base() + [
            'slug' => Str::random(22), 'doc_type' => 'DNI', 'num_doc' => '49000001',
            'name' => 'Juan', 'lastname' => 'Paz', 'is_active' => true,
        ]);

        $plan->people()->create(['slug' => Str::random(22), 'person_id' => $persona->id]);
    }

    private function conFormato(WorkPlan $plan): \App\Models\FormTemplate
    {
        $plantilla = \App\Models\FormTemplate::create($this->base() + [
            'slug' => Str::random(22), 'code' => 'AST', 'kind' => \App\Models\FormTemplate::STRUCTURED,
            'status' => 'published', 'version' => 1,
        ]);

        $plan->workType->formTemplates()->attach($plantilla->id, ['is_required' => true]);

        return $plantilla;
    }

    /** Todos los trabajadores del plan, firmados. */
    private function firmarCuadrilla(WorkPlan $plan): void
    {
        $plan->people()->update(['is_approved' => true]);
    }

    /** Todos los formatos que el plan exige, confirmados. */
    private function confirmarFormatos(WorkPlan $plan): void
    {
        foreach ($plan->expectedFormTemplates() as $id => $item) {
            $plan->submissions()->updateOrCreate(
                ['form_template_id' => $id],
                ['slug' => Str::random(22), 'template_version' => 1, 'status' => 'confirmed'],
            );
        }
    }

    /**
     * Alguien que responda por los trabajadores.
     *
     * Antes esto se colaba por la via de las aprobaciones —habia una regla
     * obligatoria de rol trabajador— y estas pruebas no tenian que montarlo.
     * Al salir del flujo dejo de ser una aprobacion y paso a ser una columna
     * del plan, y el cierre lo exige aparte: sin esto un plan con gente en la
     * obra se cerraria sin nadie que responda por ella.
     *
     * Se designa **despues** de firmar, porque el representante sale de los que
     * ya firmaron. Es lo que comprueba `designarRepresentante()`, y es la razon
     * de que la firma tenga que estar puesta antes.
     */
    private function conRepresentante(WorkPlan $plan): void
    {
        $persona = $plan->people()->with('person')->first()?->person;

        if ($persona) {
            app(WorkPlanSetupService::class)->designarRepresentante($plan, $persona);
        }
    }

    /**
     * El plan completo: firmas, representante y formatos. Le faltan solo las
     * aprobaciones.
     */
    private function completar(WorkPlan $plan): void
    {
        $this->firmarCuadrilla($plan);
        $this->conRepresentante($plan);
        $this->confirmarFormatos($plan);
    }

    private function aprobacion(WorkPlan $plan, bool $obligatoria, bool $firmada, int $prioridad = 1): void
    {
        $regla = ApprovalRule::create($this->base() + [
            'slug' => Str::random(22), 'approver_role' => 'supervisor',
            'priority_level' => $prioridad, 'is_required' => $obligatoria, 'is_active' => true,
        ]);

        $persona = Person::create($this->base() + [
            'slug' => Str::random(22), 'doc_type' => 'DNI', 'num_doc' => '4000000' . $prioridad,
            'name' => 'Ana', 'lastname' => 'Ruiz', 'is_active' => true,
        ]);

        $plan->approvals()->create([
            'slug' => Str::random(22), 'approval_rule_id' => $regla->id,
            'person_id' => $persona->id, 'is_required' => $obligatoria, 'is_approved' => $firmada,
        ]);
    }
}
