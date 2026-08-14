<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\WorkPlan;
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Una inspeccion sin decir DE QUE herramienta no es una inspeccion.
 *
 * Lo pidio el dueño del producto con estas palabras: «lo que esta en el campo
 * de herramienta debe ser obligatorio». Se podia marcar «No cumple» en veinte
 * puntos y confirmar el IHM sin nombrar la herramienta: un papel que dice que
 * ALGO esta mal sin decir que.
 *
 * La regla es la misma que la del peligro entero de la matriz, y condicional
 * por lo mismo: una fila recien añadida y vacia no molesta; en cuanto dice algo
 * —un punto marcado, una medida escrita— tiene que decir de que habla.
 */
class HerramientaConNombreTest extends TestCase
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
    }

    /** Una fila con puntos marcados y sin nombre no se guarda. */
    public function test_una_fila_con_respuestas_exige_el_nombre(): void
    {
        [$entrega] = $this->entrega();

        $this->expectException(\DomainException::class);

        app(FormSubmissionService::class)->responder($entrega, [[
            'code'  => 'inspeccion',
            'row'   => 0,
            'value' => ['tool' => null, 'items' => [['item' => 'Mango', 'answer' => 'No cumple']]],
        ]]);
    }

    /** Una medida de correccion escrita tambien es «decir algo». */
    public function test_una_correccion_escrita_tambien_exige_el_nombre(): void
    {
        [$entrega] = $this->entrega();

        $this->expectException(\DomainException::class);

        app(FormSubmissionService::class)->responder($entrega, [[
            'code'  => 'inspeccion',
            'row'   => 0,
            'value' => ['tool' => '', 'items' => [], 'correction_measure' => 'Cambiar el mango'],
        ]]);
    }

    /** Con su nombre, la misma fila entra sin protestar. */
    public function test_con_nombre_la_fila_se_guarda(): void
    {
        [$entrega] = $this->entrega();

        app(FormSubmissionService::class)->responder($entrega, [[
            'code'  => 'inspeccion',
            'row'   => 0,
            'value' => ['tool' => 'Martillo', 'items' => [['item' => 'Mango', 'answer' => 'No cumple']]],
        ]]);

        $this->assertSame(1, $entrega->answers()->count());
    }

    /**
     * Una fila vacia no molesta: es un hueco, no una infraccion.
     *
     * `conforme: false` acompaña siempre a la fila que emite la pantalla y es
     * un DERIVADO, no una declaracion: no convierte la fila vacia en «dice
     * algo».
     */
    public function test_una_fila_vacia_no_exige_nada(): void
    {
        [$entrega] = $this->entrega();

        app(FormSubmissionService::class)->responder($entrega, [[
            'code'  => 'inspeccion',
            'row'   => 0,
            'value' => ['tool' => null, 'items' => [['item' => 'Mango', 'answer' => null]], 'conforme' => true],
        ]]);

        $this->assertSame(1, $entrega->answers()->count());
    }

    /** Y la pantalla marca la fila en el sitio, no solo al guardar. */
    public function test_la_pantalla_marca_el_nombre_que_falta(): void
    {
        $vista = file_get_contents(resource_path('js/Components/FormFields/ToolChecklistField.vue'));

        $this->assertStringContainsString('faltaNombre(fila)', $vista);
        $this->assertStringContainsString('field_work.tool_checklist.tool_required', $vista,
            'la nota de la fila dice la misma frase que la regla del servidor');
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    /** @return array{0: FormSubmission} */
    private function entrega(): array
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
            'code' => 'IHM', 'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => 1, 'requires_signature' => false, 'published_at' => now(), 'is_active' => true,
        ]);

        $seccion = FormSection::create([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'code' => 'inspeccion', 'name_es' => 'Inspección', 'position' => 1,
        ]);

        FormField::create([
            'slug' => Str::random(22), 'form_section_id' => $seccion->id,
            'code' => 'inspeccion', 'field_type' => 'tool_checklist',
            'is_required' => true, 'position' => 1,
            'config' => [
                'tools' => ['Martillo'], 'items' => ['Mango'],
                'answers' => ['Cumple', 'No aplica', 'No cumple'],
                'extra' => ['correction_measure', 'responsible', 'correction_verification'],
            ],
        ]);

        $sede = \App\Models\WorkLocation::create(['slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1, 'name' => 'Planta', 'is_active' => true]);

        $plan = WorkPlan::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
            'company_id' => \App\Models\Company::create(['slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1, 'name' => 'ACME', 'complete_name' => 'ACME SA', 'num_doc' => '20100000001', 'is_active' => true])->id,
            'work_type_id' => \App\Models\WorkType::create(['slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1, 'code' => 'MANT', 'is_active' => true])->id,
            'work_location_id' => $sede->id,
            'user_id' => \App\Models\User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code' => 'PE26-0814-0002', 'description' => 'Trabajo', 'date_start' => '2026-08-14', 'is_active' => true,
        ]);

        return [FormSubmission::create([
            'slug' => Str::random(22), 'tenant_id' => 1,
            'work_plan_id' => $plan->id, 'form_template_id' => $plantilla->id,
            'template_version' => 1, 'status' => 'draft',
        ])];
    }
}
