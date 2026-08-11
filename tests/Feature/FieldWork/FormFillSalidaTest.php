<?php

namespace Tests\Feature\FieldWork;

use App\Models\Company;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * De la pantalla de llenado se puede SALIR.
 *
 * No se podia. El pie tenia dos botones llenando —«Confirmar formato» y
 * «Guardar»— y uno mirando un formato ya confirmado —«Volver a editar»—, y los
 * tres escriben en la base: cerrar el documento, guardar respuestas o reabrirlo.
 * Entrar a MIRAR un formato y salirse sin tocar nada no estaba previsto, y en
 * obra eso acaba en «le doy a confirmar a ver que pasa» sobre un documento que
 * puede terminar delante de un inspector.
 *
 * Se colo porque la pantalla se penso para el que llena y ya, cuando la abren
 * tambien el supervisor —que revisa— y el auditor —que solo mira—. Peor todavia
 * en solo lectura: la franja entera colgaba de `canReopen`, asi que a quien no
 * puede reabrir —el auditor, o cualquiera con el plan ya cerrado— no le salia
 * ni pie. Justo a quien no tiene nada mas que hacer en esa pantalla.
 *
 * Lo que se comprueba aqui es lo que se puede comprobar sin navegador: que el
 * servidor manda a donde hay que volver, y que la pantalla tiene la salida en
 * los dos estados. Que la barra mida lo que tiene que medir se mira en un
 * navegador de verdad (docs/UI.md §9).
 */
class FormFillSalidaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['form_submissions.view', 'form_submissions.edit', 'work_plans.view'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);

        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
        ]);
    }

    /**
     * El slug del plan llega a la pantalla.
     *
     * Es el motivo de que no hubiera salida que se pudiera escribir: la
     * pantalla recibia la entrega, la plantilla y las respuestas, y de ninguna
     * de las tres sale el plan del que cuelga.
     */
    public function test_la_pantalla_recibe_el_plan_al_que_volver(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('FieldWork/FormFill')
                ->where('plan.slug', $plan->slug)
                ->where('canViewPlan', true));
    }

    /**
     * Y si el usuario no puede ver la ficha del plan, se dice.
     *
     * La ficha pide `work_plans.view` y esta pantalla solo `form_submissions.view`.
     * Los perfiles sembrados llevan los dos, pero un rol a medida puede no
     * llevarlos, y mandarle a la ficha seria un 403: un boton que solo puede
     * fallar es peor que no tenerlo (docs/UI.md §6). Con esta bandera la
     * pantalla lo lleva a la lista de formatos del plan, que pide justo el
     * permiso con el que ha llegado hasta aqui.
     */
    public function test_sin_permiso_para_la_ficha_del_plan_se_avisa_a_la_pantalla(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->actingAs($this->actor(conPlan: false))
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canViewPlan', false));
    }

    /**
     * Los dos destinos existen y responden, que es lo que hace que la salida no
     * sea un boton que falla.
     */
    public function test_los_dos_destinos_de_la_salida_se_abren(): void
    {
        [$plan] = $this->escenario();

        $this->actingAs($this->actor())
            ->get(route('business_management.work_plans.show', $plan->slug))
            ->assertOk();

        $this->actingAs($this->actor(conPlan: false))
            ->get(route('field_work.forms.index', $plan->slug))
            ->assertOk();
    }

    /**
     * La salida esta en los DOS estados: llenando y mirando lo confirmado.
     *
     * Se mira el fuente porque esto es una decision de plantilla —que botones
     * lleva cada rama del pie— y no hay nada en la respuesta del servidor que lo
     * delate. La franja de solo lectura ya no puede volver a colgar de
     * `canReopen`: era la manera de quedarse sin pie.
     */
    public function test_hay_salida_llenando_y_mirando(): void
    {
        $pantalla = $this->pantalla();

        $this->assertSame(2, substr_count($pantalla, "\$t('field_work.back_to_plan')"),
            'La salida tiene que estar en las dos barras: la de llenado y la de solo lectura.');

        $this->assertStringNotContainsString('v-else-if="canReopen" class="sap-actionbar', $pantalla,
            'La franja de solo lectura vuelve a colgar de `canReopen`: quien solo mira se queda sin pie y sin salida.');
    }

    /** Y sigue habiendo UNA barra, la compartida, tambien en solo lectura. */
    public function test_las_dos_barras_son_la_compartida(): void
    {
        $this->assertSame(2, substr_count($this->pantalla(), 'class="sap-actionbar ff-actions"'));
    }

    /**
     * «Guardar» no decia que se guarda ni que pasa despues; al lado de
     * «Confirmar formato» se leia como el paso previo a cerrar el documento.
     */
    public function test_guardar_dice_que_guarda_los_cambios(): void
    {
        $this->assertSame('Guardar cambios', $this->texto('es', 'save'));
        $this->assertSame('Save changes', $this->texto('en', 'save'));
    }

    /** Los textos de la salida existen en los dos idiomas (docs/UI.md §7). */
    public function test_los_textos_de_la_salida_estan_en_los_dos_idiomas(): void
    {
        foreach (['es', 'en'] as $idioma) {
            foreach (['back_to_plan', 'leave_title', 'leave_help', 'leave_discard', 'leave_stay'] as $clave) {
                $this->assertNotSame('', (string) $this->texto($idioma, $clave),
                    "Falta `field_work.{$clave}` en {$idioma}.");
            }
        }
    }

    /**
     * El aviso al salir es CONDICIONAL, no de cada vez.
     *
     * Preguntar siempre entrena a contestar sin leer: con guantes y prisa, el
     * aviso numero veinte se despacha igual de rapido que el que de verdad tenia
     * media matriz de riesgo detras. Por eso se compara lo que hay en pantalla
     * con lo ultimo que el servidor acepto, y solo se pregunta si no coinciden.
     */
    public function test_solo_se_pregunta_si_hay_algo_sin_guardar(): void
    {
        $pantalla = $this->pantalla();

        $this->assertStringContainsString('if (huella() === guardado) { router.get(volverA.value); return; }', $pantalla,
            'Sin cambios pendientes se sale directo: un aviso que sale siempre deja de avisar.');

        $this->assertStringContainsString('onSuccess: () => { guardado = huella(); seguir?.(); }', $pantalla,
            'La foto de lo guardado solo se renueva cuando el servidor ha dicho que si.');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

    private function pantalla(): string
    {
        return file_get_contents(resource_path('js/Pages/FieldWork/FormFill.vue'));
    }

    /** Se lee el fichero, no `__()`: aqui interesan los dos idiomas a la vez. */
    private function texto(string $idioma, string $clave): string
    {
        $textos = require resource_path("lang/{$idioma}/field_work.php");

        return $textos[$clave] ?? '';
    }

    private function actor(bool $conPlan = true): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(array_merge(
            ['form_submissions.view', 'form_submissions.edit'],
            $conPlan ? ['work_plans.view'] : [],
        ));

        return $u;
    }

    /** @return array{0: WorkPlan, 1: FormTemplate, 2: FormSubmission} */
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
            'form_section_id' => $seccion->id, 'code' => 'actividad',
            'field_type' => 'text', 'is_required' => false, 'position' => 1,
        ]);

        $entrega = FormSubmission::create($base + [
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1, 'status' => 'draft',
        ]);

        return [$plan, $plantilla, $entrega];
    }
}
