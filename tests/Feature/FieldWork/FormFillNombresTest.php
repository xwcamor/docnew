<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
use App\Models\FormSection;
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
 * La pantalla de llenado lee los nombres que estan en la base.
 *
 * El motor nacio sabiendo codigos: la cabecera decia «AST» —una sigla que hay
 * que saberse—, las secciones eran tarjetas sin titulo y la etiqueta de cada
 * campo salia de humanizar el codigo. Ya hay columnas para esos textos
 * (`form_sections.name_es`, `form_fields.label_es`, y sus `_en`), con el accesor
 * `label` eligiendo por el idioma en curso.
 *
 * Un accesor NO viaja solo en el JSON de Inertia, asi que la pantalla se quedaba
 * en la cadena de respaldo aunque los nombres estuvieran guardados. Eso es lo
 * que se comprueba aqui: que el controlador los pone en el payload.
 */
class FormFillNombresTest extends TestCase
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
        foreach (['form_submissions.view', 'form_submissions.edit'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);

        // El entorno de pruebas arranca en ingles. Aqui se mira justo el idioma
        // en curso, asi que se fija a mano en vez de dejarlo al azar del
        // `config/app.php` de quien corra la suite.
        app()->setLocale('es');

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    /** El formato, su seccion y sus campos llegan con el nombre puesto. */
    public function test_el_formato_llega_con_los_nombres_de_seccion_y_campo(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FieldWork/FormFill')
                ->where('template.label', 'AST (Análisis de Seguridad en el Trabajo)')
                ->where('template.sections.0.label', 'Trabajos a realizar')
                ->where('template.sections.0.fields.0.label', 'Actividad realizada'));
    }

    /**
     * En ingles, el mismo formato se lee en ingles.
     *
     * Es la razon de que los nombres sean columnas y no cadenas de
     * `resources/lang`: media aplicacion estaba en ingles y los formatos solo en
     * castellano.
     */
    public function test_en_ingles_se_leen_los_nombres_en_ingles(): void
    {
        [$plan, $plantilla] = $this->escenario();

        app()->setLocale('en');

        $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('template.sections.0.label', 'Work to be carried out')
                ->where('template.sections.0.fields.0.label', 'Task performed'));
    }

    /**
     * Un campo de antes de las columnas sigue leyendose.
     *
     * Su etiqueta vivia en `config.label` —era el unico sitio que habia— y hay
     * formatos guardados asi. El accesor mantiene esa cadena de respaldo entera,
     * y por eso el duplicado que escribia el seeder se pudo quitar sin que nada
     * se quedara mudo.
     */
    public function test_un_campo_viejo_conserva_su_etiqueta_de_config(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $plantilla->sections()->first()->fields()->create([
            'code' => 'observaciones', 'field_type' => 'textarea', 'position' => 2,
            'config' => ['label' => 'Observaciones del supervisor'],
        ]);

        $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('template.sections.0.fields.1.label', 'Observaciones del supervisor'));
    }

    /** Y uno sin etiqueta por ningun lado cae al codigo humanizado, como siempre. */
    public function test_un_campo_sin_etiqueta_cae_al_codigo_humanizado(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $plantilla->sections()->first()->fields()->create([
            'code' => 'hora_de_inicio', 'field_type' => 'time', 'position' => 3,
        ]);

        $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('template.sections.0.fields.1.label', 'Hora de inicio'));
    }

    /**
     * Una entrega confirmada se lee, y lo que se lee tiene que estar escrito en
     * castellano —o en ingles—, no en JSON.
     *
     * El AST firmado enseñaba sus objetivos como `[ "Retirar bloqueos" ]`, con
     * corchetes y comillas, porque la rama de solo lectura pintaba el valor a
     * pelo y Vue serializa lo que no es una cadena; una casilla marcada salia
     * como «true». Lo guardado siempre estuvo bien: lo que estaba mal era como
     * se leia, y es lo que el supervisor tiene delante en el documento cerrado.
     *
     * Se mira el fuente de la pantalla y no una respuesta HTTP porque esto pasa
     * al pintar, en el navegador, y no hay nada en lo que manda el servidor que
     * lo delate. La comprobacion en un navegador de verdad va aparte
     * (`docs/UI.md §9`).
     */
    public function test_lo_confirmado_no_se_lee_en_json(): void
    {
        $pantalla = file_get_contents(resource_path('js/Pages/FieldWork/FormFill.vue'));

        $this->assertStringNotContainsString(
            '<span class="ff-readonly">{{ valores[c.id] ?? \'—\' }}</span>',
            $pantalla,
            'La rama de solo lectura vuelve a pintar el valor a pelo: una lista sale con corchetes y una casilla como «true».',
        );

        $this->assertStringContainsString('const enLimpio =', $pantalla);
        $this->assertStringContainsString('{{ enLimpio(valores[c.id]) }}', $pantalla);

        // Y los dos textos que necesita existen en los dos idiomas: sin ellos,
        // una casilla marcada saldria como «global.yes».
        foreach (['es', 'en'] as $idioma) {
            $global = require resource_path("lang/{$idioma}/global.php");

            $this->assertArrayHasKey('yes', $global);
            $this->assertArrayHasKey('no', $global);
        }
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function actor(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(['form_submissions.view', 'form_submissions.edit']);

        return $u;
    }

    /** @return array{0: WorkPlan, 1: FormTemplate} */
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
            'slug' => Str::random(22), 'code' => 'AST',
            'name'    => 'AST (Análisis de Seguridad en el Trabajo)',
            'name_es' => 'AST (Análisis de Seguridad en el Trabajo)',
            'name_en' => 'JSA (Job Safety Analysis)',
            'kind' => FormTemplate::STRUCTURED, 'status' => 'published', 'version' => 1,
            'requires_signature' => false, 'published_at' => now(),
        ]);

        $seccion = FormSection::create([
            'form_template_id' => $plantilla->id, 'position' => 1,
            'name_es' => 'Trabajos a realizar', 'name_en' => 'Work to be carried out',
        ]);

        $seccion->fields()->create([
            'code' => 'actividad', 'field_type' => 'text', 'position' => 1,
            'label_es' => 'Actividad realizada', 'label_en' => 'Task performed',
        ]);

        return [$plan, $plantilla];
    }
}
