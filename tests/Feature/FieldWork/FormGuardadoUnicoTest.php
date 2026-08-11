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
use App\Services\FieldWork\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Guardar y confirmar son UN solo gesto.
 *
 * La pantalla de llenado tenia dos botones —«Confirmar formato» y «Guardar
 * cambios»— y el dueño del producto lo dijo tal cual: nadie guarda para
 * despues volver a confirmar. Ahora el cliente solo manda las respuestas
 * (`forms.answer`) y el que decide es el servidor:
 *
 * - Completo → confirma y manda a la ficha del plan con el aviso de exito.
 * - Faltan campos → lo guardado SE QUEDA guardado (en obra se llena por
 *   partes), el formato sigue en borrador y se vuelve a la pantalla, que al
 *   recargar sus props recibe la lista de faltantes: las etiquetas para el
 *   aviso y los CODIGOS para marcar en rojo cada campo pendiente.
 *
 * Guardar a medias es lo normal, no un error: por eso el camino incompleto no
 * pasa por la `DomainException` de `confirmar()` —que el handler global pinta
 * como flash rojo— sino que pregunta primero que falta.
 */
class FormGuardadoUnicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Los mensajes se comprueban como los lee quien esta en obra: la suite
        // arranca en ingles y aqui el idioma se fija a proposito.
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

    /** Guardar con todo lo obligatorio puesto confirma y se va a la ficha del plan. */
    public function test_guardar_completo_confirma_y_redirige_al_plan(): void
    {
        [$plan, , $entrega] = $this->escenario();

        $this->actingAs($this->actor())
            ->post(route('field_work.forms.answer', $entrega->slug), [
                'answers' => [
                    ['code' => 'actividad', 'value' => 'Izaje de valvulas'],
                    ['code' => 'herramientas', 'value' => 'Tecle y eslingas'],
                ],
            ])
            // A la ficha del plan: nadie quiere quedarse mirando un formato ya
            // cerrado — la queja original era tener que pulsar «regresar».
            ->assertRedirect(route('business_management.work_plans.show', $plan->slug))
            ->assertSessionHas('success', __('field_work.saved_confirmed'))
            ->assertSessionMissing('error');

        $entrega->refresh();

        $this->assertSame('confirmed', $entrega->status);
        $this->assertNotNull($entrega->submitted_at);
    }

    /**
     * Sin permiso para la ficha del plan, el destino es la lista de formatos.
     *
     * El mismo criterio que la salida del pie (`canViewPlan`): redirigir a una
     * pantalla que va a contestar 403 es un boton que solo puede fallar.
     */
    public function test_sin_permiso_para_el_plan_redirige_a_la_lista_de_formatos(): void
    {
        [$plan, , $entrega] = $this->escenario();

        $this->actingAs($this->actor(conPlan: false))
            ->post(route('field_work.forms.answer', $entrega->slug), [
                'answers' => [
                    ['code' => 'actividad', 'value' => 'Izaje de valvulas'],
                    ['code' => 'herramientas', 'value' => 'Tecle y eslingas'],
                ],
            ])
            ->assertRedirect(route('field_work.forms.index', $plan->slug));

        $this->assertSame('confirmed', $entrega->fresh()->status);
    }

    /**
     * Guardar a medias NO es un error: lo guardado se queda, el formato sigue
     * en borrador y no hay flash rojo por ningun lado.
     */
    public function test_guardar_incompleto_guarda_y_se_queda_en_borrador_sin_error(): void
    {
        [$plan, $plantilla, $entrega] = $this->escenario();

        $this->actingAs($this->actor())
            ->from(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->post(route('field_work.forms.answer', $entrega->slug), [
                // Solo uno de los dos obligatorios: en obra se llena por partes.
                'answers' => [['code' => 'actividad', 'value' => 'Izaje de valvulas']],
            ])
            ->assertRedirect(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertSessionHas('success', __('field_work.saved_partial'))
            ->assertSessionMissing('error');

        $entrega->refresh();

        $this->assertSame('draft', $entrega->status);
        $this->assertSame('Izaje de valvulas', $entrega->answers()->first()->value_text,
            'el trabajo parcial se perdio: guardar a medias tiene que guardar');
    }

    /**
     * Tras un guardado incompleto, la pantalla recibe QUE falta y COMO
     * encontrarlo: las etiquetas para el aviso y los codigos para marcar en
     * rojo el campo. Y `attempted` en verdadero, que es lo que enciende el
     * marcado: ya hubo un intento.
     */
    public function test_tras_un_guardado_incompleto_llegan_los_codes_de_los_faltantes(): void
    {
        [$plan, $plantilla, $entrega] = $this->escenario();
        $actor = $this->actor();

        $this->actingAs($actor)->post(route('field_work.forms.answer', $entrega->slug), [
            'answers' => [['code' => 'actividad', 'value' => 'Izaje de valvulas']],
        ]);

        $this->actingAs($actor)
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('missing', ['Herramientas usadas'])
                ->where('missingCodes', ['herramientas'])
                ->where('attempted', true));
    }

    /**
     * Un formato recien abierto, sin nada guardado, dice lo que falta pero con
     * `attempted` en falso: pintar de rojo un formulario vacio es gritarle a
     * quien todavia no ha hecho nada.
     */
    public function test_un_formato_virgen_trae_los_faltantes_pero_sin_intento(): void
    {
        [$plan, $plantilla] = $this->escenario();

        $this->actingAs($this->actor())
            ->get(route('field_work.forms.open', [$plan->slug, $plantilla->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('missingCodes', ['actividad', 'herramientas'])
                ->where('attempted', false));
    }

    /**
     * La HOJA X: el formato que es solo la foto del papel. No hay nada que
     * teclear, asi que el guardado tiene que aceptar una lista vacia — sin
     * adjunto se queda en borrador, y con el adjunto puesto confirma.
     */
    public function test_la_hoja_x_confirma_al_guardar_una_vez_adjuntado_el_archivo(): void
    {
        Storage::fake('local');

        [$plan, , $entrega] = $this->escenario(kind: FormTemplate::UPLOAD_ONLY);
        $actor = $this->actor();

        $this->actingAs($actor)
            ->post(route('field_work.forms.answer', $entrega->slug), ['answers' => []])
            ->assertSessionHas('success', __('field_work.saved_partial'))
            ->assertSessionMissing('error');

        $this->assertSame('draft', $entrega->fresh()->status);

        app(FormSubmissionService::class)->adjuntar($entrega, 'la-foto-del-papel', 'image/png');

        $this->actingAs($actor)
            ->post(route('field_work.forms.answer', $entrega->slug), ['answers' => []])
            ->assertRedirect(route('business_management.work_plans.show', $plan->slug))
            ->assertSessionHas('success', __('field_work.saved_confirmed'));

        $this->assertSame('confirmed', $entrega->fresh()->status);
    }

    /**
     * El candado de siempre, que este flujo no puede aflojar: un formato
     * confirmado no acepta respuestas (la prueba fina esta en FormReopenTest;
     * aqui se comprueba que el guardado unico pasa por el mismo camino).
     */
    public function test_un_formato_confirmado_sigue_sin_aceptar_respuestas(): void
    {
        [, , $entrega] = $this->escenario();
        $entrega->update(['status' => 'confirmed', 'submitted_at' => now()]);

        $this->actingAs($this->actor())
            ->post(route('field_work.forms.answer', $entrega->slug), [
                'answers' => [['code' => 'actividad', 'value' => 'Lo que alguien quiso colar']],
            ])
            ->assertSessionHas('error', __('field_work.confirmed_reopen_first'));

        $this->assertNull($entrega->answers()->first(), 'se escribio en un formato ya confirmado');
    }

    // ── apoyo ────────────────────────────────────────────────────────────────

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

    /**
     * Un plan con un formato de dos campos obligatorios: `actividad` (que las
     * pruebas llenan) y `herramientas` (que dejan a medias).
     *
     * @return array{0: WorkPlan, 1: FormTemplate, 2: FormSubmission}
     */
    private function escenario(string $kind = FormTemplate::STRUCTURED): array
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
            'slug' => Str::random(22), 'code' => 'AST', 'kind' => $kind,
            'status' => 'published', 'version' => 1, 'requires_signature' => false, 'published_at' => now(),
        ]);

        $seccion = FormSection::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'code' => 'general', 'position' => 1,
        ]);

        FormField::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'form_section_id' => $seccion->id, 'code' => 'actividad',
            'label_es' => 'Actividad realizada', 'label_en' => 'Task performed',
            'field_type' => 'text', 'is_required' => true, 'position' => 1,
        ]);

        FormField::create($base + [
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'form_section_id' => $seccion->id, 'code' => 'herramientas',
            'label_es' => 'Herramientas usadas', 'label_en' => 'Tools used',
            'field_type' => 'text', 'is_required' => true, 'position' => 2,
        ]);

        $entrega = FormSubmission::create($base + [
            'slug' => Str::random(22), 'work_plan_id' => $plan->id,
            'form_template_id' => $plantilla->id, 'template_version' => 1, 'status' => 'draft',
        ]);

        return [$plan, $plantilla, $entrega];
    }
}
