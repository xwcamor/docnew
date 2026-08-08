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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El plan se cierra solo, como en el sistema anterior.
 *
 * `Plan#lock_plan_if_all_conditions_met` ponia `is_locked` e `is_done` a la vez
 * en cuanto habia hora de fin y no quedaba ninguna aprobacion obligatoria sin
 * firmar. Es la lógica que cerro **3 297 de los 3 653 planes** de los datos
 * reales, ninguno a mano.
 *
 * No se habia portado: aqui `is_done` solo se ponia editando el plan. Con eso,
 * el 90% de los planes se habria quedado abierto para siempre.
 *
 * Estas pruebas fijan las DOS condiciones y, sobre todo, **que no haya una
 * tercera**: la v1 no miraba si los formatos estaban confirmados ni si habia
 * firmado toda la cuadrilla. Añadirla desalinearia los planes migrados con los
 * que se creen a partir de ahora.
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
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertTrue($this->cierre->evaluar($plan));

        $plan->refresh();
        $this->assertTrue($plan->is_done);
        $this->assertTrue($plan->is_closed);
    }

    public function test_sin_hora_de_fin_no_se_cierra(): void
    {
        $plan = $this->plan(['date_end' => null]);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertFalse($plan->refresh()->is_done);
    }

    public function test_con_una_obligatoria_sin_firmar_no_se_cierra(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->aprobacion($plan, obligatoria: true, firmada: true);
        $this->aprobacion($plan, obligatoria: true, firmada: false, prioridad: 2);

        $this->assertFalse($this->cierre->evaluar($plan));
        $this->assertFalse($plan->refresh()->is_done);
    }

    /** Una opcional sin firmar no frena nada: para eso es opcional. */
    public function test_una_opcional_sin_firmar_no_frena_el_cierre(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->aprobacion($plan, obligatoria: true,  firmada: true);
        $this->aprobacion($plan, obligatoria: false, firmada: false, prioridad: 2);

        $this->assertTrue($this->cierre->evaluar($plan));
        $this->assertTrue($plan->refresh()->is_done);
    }

    /**
     * Y **no** se añade una tercera condicion.
     *
     * Un plan con la hora de fin y sus firmas se cierra aunque los formatos
     * esten a medias. Suena raro y es lo que hacia la v1: en obra el documento
     * lo cierra la firma del que autoriza, que es quien responde por el resto.
     */
    public function test_no_exige_que_los_formatos_esten_confirmados(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        // El AST del plan, abierto y a medio llenar.
        $plan->submissions()->create([
            'slug' => Str::random(22),
            'form_template_id' => $plan->workType->formTemplates()->first()->id,
            'template_version' => 1, 'status' => 'draft',
        ]);

        $this->assertTrue($this->cierre->evaluar($plan));
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
        $this->aprobacion($plan, obligatoria: true, firmada: true);

        $this->assertTrue($this->cierre->evaluar($plan));
        $this->assertFalse($this->cierre->evaluar($plan->refresh()));
    }

    /** Un plan armado, sin aprobaciones y con fin se cierra: no falta nada. */
    public function test_un_plan_sin_aprobaciones_se_cierra_con_la_hora_de_fin(): void
    {
        $plan = $this->plan(['date_end' => '2026-08-08 18:00:00']);

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

        // Se compara contra la traduccion, no contra un literal: la suite corre
        // en ingles y el texto en duro solo probaria en que idioma esta.
        $this->assertSame([
            __('work_plans.close_needs_date_end'),
            trans_choice('work_plans.close_needs_approvals', 2, ['count' => 2]),
        ], $falta);
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
