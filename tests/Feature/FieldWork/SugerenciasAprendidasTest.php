<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las sugerencias de los textos libres aprenden de los documentos anteriores.
 *
 * La automatizacion que pidio el dueño del producto: el catalogo fijo de la v1
 * sugiere poco (de 3 561 peligros distintos tecleados en los AST reales, 3 492
 * no estan en `ast_dangers`), asi que al abrir la pantalla de llenado la
 * config del campo llega con los textos ya usados en entregas CONFIRMADAS de
 * la misma plantilla, detras del catalogo fijo y sin repetidos.
 *
 * Es presentacion pura: nada se persiste, el catalogo guardado en la
 * plantilla no cambia. Y solo se aprende de lo confirmado — un borrador puede
 * tener cualquier cosa a medio teclear, y sugerirla es propagar el error.
 */
class SugerenciasAprendidasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['form_submissions.view', 'work_plans.view'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    /** Lo escrito en una entrega confirmada aparece detras del catalogo fijo. */
    public function test_lo_confirmado_se_suma_detras_del_catalogo(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->entregaConMatriz($plan, $plantilla, 'confirmed', [
            ['actividad' => 'Izaje de valvulas', 'peligro' => 'Carga suspendida', 'riesgo' => 'Aplastamiento', 'control' => 'Vigia y señalizacion'],
        ]);

        $this->abrir($plan, $plantilla)
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('template.sections.0.fields.0.config.activities', ['Del catalogo', 'Izaje de valvulas'])
                ->where('template.sections.0.fields.0.config.dangers', ['Golpes', 'Carga suspendida'])
                ->where('template.sections.0.fields.0.config.risks', ['Contusiones', 'Aplastamiento'])
                ->where('template.sections.0.fields.0.config.controls', ['EPP basico', 'Vigia y señalizacion'])
            );
    }

    /** Un borrador no enseña nada: solo se aprende de lo confirmado. */
    public function test_un_borrador_no_sugiere(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->entregaConMatriz($plan, $plantilla, 'draft', [
            ['actividad' => 'a medio tecle', 'peligro' => 'peligr', 'riesgo' => 'x', 'control' => 'y'],
        ]);

        $this->abrir($plan, $plantilla)
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('template.sections.0.fields.0.config.activities', ['Del catalogo'])
                ->where('template.sections.0.fields.0.config.dangers', ['Golpes'])
            );
    }

    /**
     * Lo que ya esta en el catalogo no se repite, aunque cambie la caja o los
     * espacios: «golpes » y «Golpes» son el mismo texto.
     */
    public function test_lo_del_catalogo_no_se_duplica(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->entregaConMatriz($plan, $plantilla, 'confirmed', [
            ['actividad' => 'Del catalogo', 'peligro' => ' golpes ', 'riesgo' => 'CONTUSIONES', 'control' => 'Otro control'],
        ]);

        $this->abrir($plan, $plantilla)
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('template.sections.0.fields.0.config.activities', ['Del catalogo'])
                ->where('template.sections.0.fields.0.config.dangers', ['Golpes'])
                ->where('template.sections.0.fields.0.config.risks', ['Contusiones'])
                ->where('template.sections.0.fields.0.config.controls', ['EPP basico', 'Otro control'])
            );
    }

    /** Lo mas escrito va primero entre lo aprendido: se sugiere por frecuencia. */
    public function test_lo_mas_usado_va_primero(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->entregaConMatriz($plan, $plantilla, 'confirmed', [
            ['actividad' => 'Rara', 'peligro' => 'Comun', 'riesgo' => 'r', 'control' => 'c'],
            ['actividad' => 'Frecuente', 'peligro' => 'Comun', 'riesgo' => 'r', 'control' => 'c'],
        ]);
        // Un plan solo admite UNA entrega por plantilla: la segunda va en un
        // plan hermano, que es ademas el caso real — se aprende entre planes.
        $this->entregaConMatriz($this->planHermano($plan), $plantilla, 'confirmed', [
            ['actividad' => 'Frecuente', 'peligro' => 'Otro', 'riesgo' => 'r', 'control' => 'c'],
        ]);

        $this->abrir($plan, $plantilla)
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('template.sections.0.fields.0.config.activities', ['Del catalogo', 'Frecuente', 'Rara'])
                ->where('template.sections.0.fields.0.config.dangers', ['Golpes', 'Comun', 'Otro'])
            );
    }

    /**
     * La herramienta del IHM aprende igual que los textos de la matriz.
     *
     * En la v1 tambien era un textarea libre con autocompletado
     * (`ihm-tool-autocomplete` en `_f4_document_tool_fields.html.erb`): el
     * mismo trato que actividad, peligro, riesgo y control.
     */
    public function test_la_herramienta_del_ihm_tambien_aprende(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $ihm = FormTemplate::create($base + [
            'slug' => Str::random(22), 'code' => 'IHM', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'published', 'version' => 1, 'requires_signature' => false, 'published_at' => now(),
        ]);

        $seccion = FormSection::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $ihm->id,
            'code' => 'general', 'position' => 1,
        ]);

        FormField::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $ihm->id,
            'form_section_id' => $seccion->id, 'code' => 'herramientas',
            'label_es' => 'Inspección de herramientas', 'label_en' => 'Tool inspection',
            'field_type' => 'tool_checklist', 'is_required' => true, 'position' => 1,
            'config' => ['tools' => ['Tecle'], 'items' => ['Estado general'], 'answers' => ['Cumple', 'No cumple']],
        ]);

        $entrega = FormSubmission::create($base + [
            'slug' => Str::random(22),
            'work_plan_id' => $plan->id, 'form_template_id' => $ihm->id,
            'template_version' => 1, 'status' => 'confirmed', 'submitted_at' => now(),
        ]);

        $campo = FormField::where('form_section_id', $seccion->id)->firstOrFail();

        FormAnswer::create([
            'form_submission_id' => $entrega->id, 'form_field_id' => $campo->id,
            'row_index' => 0, 'value_json' => [['tool' => 'Esmeril angular 4.5"', 'items' => []]],
        ]);

        $this->abrir($plan, $ihm)
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('template.sections.0.fields.0.config.tools', ['Tecle', 'Esmeril angular 4.5"'])
            );
    }

    /**
     * El aprendizaje viaja en la pagina, no en la plantilla: el catalogo
     * guardado queda exactamente como el administrador lo dejo.
     */
    public function test_la_plantilla_guardada_no_cambia(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->entregaConMatriz($plan, $plantilla, 'confirmed', [
            ['actividad' => 'Izaje de valvulas', 'peligro' => 'Carga suspendida', 'riesgo' => 'Aplastamiento', 'control' => 'Vigia'],
        ]);

        $this->abrir($plan, $plantilla)->assertOk();

        $campo = $this->campoMatriz($plantilla);

        $this->assertSame(['Del catalogo'], $campo->config['activities']);
        $this->assertSame(['Golpes'], $campo->config['dangers']);
    }

    private function abrir(WorkPlan $plan, FormTemplate $plantilla)
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['form_submissions.view', 'work_plans.view']);

        return $this->actingAs($u)->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]));
    }

    /**
     * Un plan con una plantilla AST cuyo campo `matriz` trae un catalogo fijo
     * minimo: una actividad, un peligro, un riesgo y un control.
     *
     * @return array{0: WorkPlan, 1: FormTemplate}
     */
    private function escenario(): array
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $empresa = Company::create($base + [
            'num_doc' => '20100000001', 'name' => 'Contratista', 'complete_name' => 'Contratista SAC',
        ]);

        $plan = WorkPlan::create($base + [
            'slug'             => Str::random(22),
            'company_id'       => $empresa->id,
            'work_type_id'     => WorkType::create($base + ['slug' => Str::random(22), 'code' => 'MTTO'])->id,
            'work_location_id' => WorkLocation::create($base + ['slug' => Str::random(22), 'name' => 'Planta'])->id,
            'user_id'          => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code'             => 'OT-1', 'description' => 'Trabajo', 'date_start' => today(),
        ]);

        $plantilla = FormTemplate::create($base + [
            'slug' => Str::random(22), 'code' => 'AST', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'published', 'version' => 1, 'requires_signature' => false, 'published_at' => now(),
        ]);

        $seccion = FormSection::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'code' => 'general', 'position' => 1,
        ]);

        FormField::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'form_section_id' => $seccion->id, 'code' => 'matriz',
            'label_es' => 'Matriz de riesgo', 'label_en' => 'Risk matrix',
            'field_type' => 'risk_matrix', 'is_required' => true, 'position' => 1,
            'config' => [
                'activities' => ['Del catalogo'],
                'dangers'    => ['Golpes'],
                'risks'      => ['Contusiones'],
                'controls'   => ['EPP basico'],
            ],
        ]);

        return [$plan, $plantilla];
    }

    /** Otro plan de la misma empresa, para poder tener otra entrega. */
    private function planHermano(WorkPlan $plan): WorkPlan
    {
        return WorkPlan::create([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'company_id' => $plan->company_id, 'work_type_id' => $plan->work_type_id,
            'work_location_id' => $plan->work_location_id, 'user_id' => $plan->user_id,
            'code' => 'OT-2', 'description' => 'Trabajo 2', 'date_start' => today(),
        ]);
    }

    private function campoMatriz(FormTemplate $plantilla): FormField
    {
        return FormField::whereIn('form_section_id', FormSection::where('form_template_id', $plantilla->id)->pluck('id'))
            ->where('code', 'matriz')
            ->firstOrFail();
    }

    /** Una entrega en el estado pedido con esas filas en la matriz. */
    private function entregaConMatriz(WorkPlan $plan, FormTemplate $plantilla, string $estado, array $filas): FormSubmission
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $entrega = FormSubmission::create($base + [
            'work_plan_id' => $plan->id, 'form_template_id' => $plantilla->id,
            'template_version' => 1, 'status' => $estado,
            'submitted_at' => $estado === 'confirmed' ? now() : null,
        ]);

        // El campo cuelga de la seccion, no de la plantilla: se busca por ahi.
        $campo = $this->campoMatriz($plantilla);

        FormAnswer::create([
            'form_submission_id' => $entrega->id, 'form_field_id' => $campo->id,
            'row_index' => 0, 'value_json' => $filas,
        ]);

        return $entrega;
    }
}
