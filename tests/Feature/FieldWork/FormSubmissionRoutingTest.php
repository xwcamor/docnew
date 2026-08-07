<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkPlan;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las rutas de una entrega se enlazan por `slug`, nunca por id.
 *
 * Es la convención de todo el proyecto —cada modelo declara
 * `getRouteKeyName() => 'slug'`— y no es cosmética: el id es un número
 * correlativo que deja adivinar cuántas entregas hay y pedir la de al lado.
 *
 * `FormSubmission` se quedó sin esa declaración al generarse el módulo,
 * mientras que la pantalla de llenado sí mandaba el slug. Resultado: guardar
 * respuestas, adjuntar la foto del papel y cerrar el formato fallaban los tres,
 * que es justo el flujo entero del trabajo en campo.
 */
class FormSubmissionRoutingTest extends TestCase
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
        foreach (['form_submissions.edit', 'form_submissions.export'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    public function test_el_modelo_declara_el_slug_como_clave_de_ruta(): void
    {
        $this->assertSame('slug', (new FormSubmission())->getRouteKeyName());
    }

    public function test_las_rutas_de_llenado_aceptan_el_slug_que_manda_la_pantalla(): void
    {
        $entrega = $this->entrega();
        $this->actingAs($this->actor());

        // Exactamente lo que hace FormFill.vue: manda `submission.slug`. La
        // prueba de que el modelo se resolvio no es el codigo de respuesta sino
        // que la accion tuvo efecto: la entrega queda confirmada.
        $this->post(route('field_work.forms.confirm', $entrega->slug));

        $this->assertSame('confirmed', $entrega->fresh()->status,
            'la ruta de confirmar no resolvio la entrega por su slug');
    }

    public function test_el_id_correlativo_no_sirve_para_pedir_una_entrega(): void
    {
        $entrega = $this->entrega();
        $this->actingAs($this->actor());

        $this->post("/field_work/submissions/{$entrega->id}/confirm");

        $this->assertSame('draft', $entrega->fresh()->status,
            'la entrega se pudo confirmar adivinando su id correlativo');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function actor(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['form_submissions.edit', 'form_submissions.export']);

        return $u;
    }

    private function entrega(): FormSubmission
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

        return FormSubmission::create($base + [
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1, 'status' => 'draft',
        ]);
    }
}
