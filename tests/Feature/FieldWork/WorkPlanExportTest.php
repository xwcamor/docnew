<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use App\Services\FieldWork\WorkPlanExportService;
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
 * El expediente de una jornada en un ZIP.
 *
 * Es el `plan_exports_controller` del sistema anterior: asi se mandaba un plan
 * fuera, al cliente o a una inspeccion. Bajarlo formato por formato son cuatro
 * clics y cuatro archivos sueltos que hay que volver a juntar.
 *
 * Dos cosas se hacen distinto que alla y las dos estan fijadas aqui:
 *
 *   - entran **los cuatro** formatos, no solo el AST y el PTF;
 *   - los PDF se generan dentro del proceso. La v1 hacia `system("curl ...")`
 *     contra su propia URL pasandole la cookie de sesion, y si curl no traia
 *     nada devolvia `nil` en silencio: el ZIP salia incompleto sin avisar.
 */
class WorkPlanExportTest extends TestCase
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
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['form_submissions.view', 'form_submissions.export', 'people.view_private_info'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    /** Los cuatro formatos, uno por PDF, con el codigo del plan en el nombre. */
    public function test_el_zip_lleva_los_cuatro_formatos_confirmados(): void
    {
        $plan = $this->plan();

        foreach (['AST', 'PTF', 'EPP', 'IHM'] as $codigo) {
            $this->entrega($plan, $codigo, 'confirmed');
        }

        $ruta = app(WorkPlanExportService::class)->zip($plan);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, 'el ZIP no se pudo abrir');
        $this->assertSame(4, $zip->numFiles);

        $dentro = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = $zip->getNameIndex($i);
            $dentro[] = $nombre;
            $this->assertStringEndsWith('.pdf', $nombre);
            // Un PDF de verdad, no un archivo vacio: es justo lo que la v1 no
            // comprobaba cuando su curl volvia con las manos vacias.
            $this->assertStringStartsWith('%PDF', $zip->getFromIndex($i));
        }
        $zip->close();
        @unlink($ruta);

        foreach (['ast', 'ptf', 'epp', 'ihm'] as $codigo) {
            $this->assertTrue(
                collect($dentro)->contains(fn ($n) => str_starts_with($n, $codigo)),
                "falta el {$codigo} en el ZIP",
            );
        }
    }

    /** Un borrador no sale: son firmas que todavia pueden cambiar. */
    public function test_los_formatos_sin_confirmar_no_entran(): void
    {
        $plan = $this->plan();
        $this->entrega($plan, 'AST', 'confirmed');
        $this->entrega($plan, 'PTF', 'draft');

        $this->assertSame(['AST'], app(WorkPlanExportService::class)
            ->exportables($plan)->map(fn ($e) => $e->formTemplate->code)->all());
    }

    /**
     * Sin nada confirmado se dice por que, en vez de mandar un ZIP vacio.
     *
     * Un archivo de cero bytes parece un fallo de la descarga y manda al
     * supervisor a reintentarlo; lo que pasa es que todavia no hay expediente.
     */
    public function test_sin_formatos_confirmados_se_explica_en_vez_de_bajar_un_zip_vacio(): void
    {
        $plan = $this->plan();
        $this->entrega($plan, 'AST', 'draft');

        $this->actingAs($this->auditor())
            ->get(route('field_work.forms.zip', $plan->slug))
            ->assertRedirect()
            ->assertSessionHas('error', __('work_plans.export_zip_empty'));
    }

    /**
     * El ZIP saca los documentos del sistema: pide permiso de exportar.
     *
     * Es el mismo que el PDF de uno solo. Sin esto, el usuario de campo que
     * llena el formato podria bajarse el expediente entero.
     */
    public function test_sin_permiso_de_exportar_no_se_baja_el_expediente(): void
    {
        $plan = $this->plan();
        $this->entrega($plan, 'AST', 'confirmed');

        $sinPermiso = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $sinPermiso->assignRole(Role::firstOrCreate(
            ['name' => 'campo', 'guard_name' => 'web'], ['description' => 'campo']));

        $respuesta = $this->actingAs($sinPermiso)->get(route('field_work.forms.zip', $plan->slug));

        // Un 403 de navegador se ve como una vuelta al panel con un aviso.
        $respuesta->assertRedirect(route('dashboard_management.dashboards.index'));
        $respuesta->assertSessionHas('error');
    }

    /**
     * Y con el permiso de exportar pero SIN el de datos privados, tampoco.
     *
     * Es el caso real que motivo el cambio: el supervisor de obra y el auditor
     * HSE exportan, y el expediente lleva los DNI completos de la cuadrilla.
     */
    public function test_exportar_no_basta_hace_falta_ver_datos_privados(): void
    {
        $plan = $this->plan();
        $this->entrega($plan, 'AST', 'confirmed');

        $soloExporta = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $soloExporta->assignRole(Role::firstOrCreate(
            ['name' => 'supervisor_obra', 'guard_name' => 'web'], ['description' => 'supervisor']));
        $soloExporta->givePermissionTo(['form_submissions.view', 'form_submissions.export']);

        $this->actingAs($soloExporta)
            ->get(route('field_work.forms.zip', $plan->slug))
            ->assertRedirect(route('dashboard_management.dashboards.index'));
    }

    /** Y con permiso, baja de verdad, con el codigo del plan en el nombre. */
    public function test_con_permiso_la_descarga_sale_con_el_nombre_del_plan(): void
    {
        $plan = $this->plan();
        $this->entrega($plan, 'AST', 'confirmed');

        $respuesta = $this->actingAs($this->auditor())->get(route('field_work.forms.zip', $plan->slug));

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('ot-1234-formatos.zip', $respuesta->headers->get('content-disposition'));
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    /**
     * Quien puede bajarse el expediente.
     *
     * Pide DOS permisos: exportar y ver datos privados. El segundo se añadio
     * cuando se cerro el agujero de que el PDF lleva dentro las firmas y los
     * DNI completos de toda la cuadrilla — sin el, el enmascarado de la
     * pantalla no servia de nada porque bastaba con descargar el documento.
     */
    private function auditor(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['form_submissions.view', 'form_submissions.export', 'people.view_private_info']);

        return $u;
    }

    private function plan(): WorkPlan
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        return WorkPlan::create($base + [
            'company_id' => Company::create([
                'slug' => Str::random(22), 'num_doc' => '20100000001', 'name' => 'Contratista',
                'complete_name' => 'Contratista SAC', 'is_active' => true,
            ] + $base)->id,
            'work_type_id' => WorkType::create(['slug' => Str::random(22)] + $base + ['code' => 'MTTO'])->id,
            'work_location_id' => WorkLocation::create(['slug' => Str::random(22)] + $base + ['name' => 'Planta'])->id,
            'user_id' => User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1])->id,
            'code' => 'OT-1234',
            'description' => 'Mantenimiento programado',
            'date_start' => today(),
        ]);
    }

    private function entrega(WorkPlan $plan, string $codigo, string $estado): FormSubmission
    {
        $base = ['slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1];

        $plantilla = FormTemplate::create($base + [
            'code' => $codigo, 'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => 1, 'requires_signature' => true, 'published_at' => now(),
        ]);

        return FormSubmission::create([
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1,
            'status' => $estado, 'tenant_id' => 1, 'created_by' => 1,
            'submitted_at' => $estado === 'confirmed' ? now() : null,
        ]);
    }
}
