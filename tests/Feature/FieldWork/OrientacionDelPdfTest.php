<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use App\Services\FieldWork\FormSubmissionPdfService;
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
 * Como se imprime un documento lo decide el documento.
 *
 * Estaba cableado en el generador: salia apaisado si la plantilla llevaba una
 * matriz de riesgo, y vertical en cualquier otro caso. Acertaba con el AST —que
 * es de donde salio la regla— y fallaba con todo lo demas: el EPP es una
 * cuadricula con una columna por equipo de proteccion y el IHM tiene veinte
 * puntos de inspeccion por herramienta, y ninguno de los dos tiene matriz
 * ninguna, asi que salian en vertical con los rotulos partidos en tiras.
 *
 * Lo dijo el dueño del producto: «esto de decir que pongas las hojas en
 * horizontal esta mal, no crees que deba haber una configuracion en formatos?».
 * El motor deja definir formatos desde la pantalla; adivinar como se imprime
 * uno nuevo mirando que campos lleva es adivinar.
 *
 * Lo que se comprueba aqui es el contrato entero: que lo dicho manda, que el
 * silencio conserva la deduccion de antes —o esto habria cambiado el aspecto de
 * documentos que nadie ha tocado—, y que se puede decir desde la pantalla
 * incluso con el documento ya publicado.
 */
class OrientacionDelPdfTest extends TestCase
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
        foreach (['view', 'show', 'create', 'edit', 'delete'] as $a) {
            Permission::firstOrCreate(['name' => "form_templates.{$a}", 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web'], ['description' => 'a']);
    }

    /**
     * Lo dicho manda, aunque la deduccion opine lo contrario.
     *
     * El caso es justo el que hacia falta: una plantilla CON matriz de riesgo
     * —o sea, la que la regla vieja mandaba apaisada— que su dueño quiere en
     * vertical. Si la deduccion siguiera ganando, la configuracion seria
     * decorativa.
     */
    public function test_lo_que_dice_el_formato_gana_a_la_deduccion(): void
    {
        $plantilla = $this->plantilla(['pdf_orientation' => 'portrait']);
        $this->conMatrizDeRiesgo($plantilla);

        $this->assertSame('portrait', $this->orientacionDe($plantilla));
    }

    /** Y al reves: sin matriz ninguna, apaisado porque lo dice el formato. */
    public function test_apaisado_sin_matriz_porque_el_formato_lo_pide(): void
    {
        $plantilla = $this->plantilla(['pdf_orientation' => 'landscape']);
        $this->conCuadricula($plantilla);

        $this->assertSame('landscape', $this->orientacionDe($plantilla));
    }

    /**
     * En silencio, la deduccion de siempre.
     *
     * Es la mitad que evita el estropicio: los formatos que ya existen tienen la
     * columna a nulo, y si el nulo significara «vertical» el AST de todo el
     * mundo habria cambiado de aspecto el dia que se añadio la columna.
     */
    public function test_sin_decir_nada_se_sigue_deduciendo(): void
    {
        $conMatriz = $this->plantilla();
        $this->conMatrizDeRiesgo($conMatriz);

        $sinMatriz = $this->plantilla(['code' => 'OTRO', 'name' => 'Otro documento']);
        $this->conCuadricula($sinMatriz);

        $this->assertSame('landscape', $this->orientacionDe($conMatriz));
        $this->assertSame('portrait', $this->orientacionDe($sinMatriz));
    }

    /**
     * Se elige desde la pantalla, y con el documento ya publicado.
     *
     * A diferencia de «como se llena», esto no cambia nada de lo guardado: solo
     * como se coloca la hoja. Un formato con años de entregas que sale mal
     * impreso tiene que poder arreglarse sin sacar una version nueva, que
     * ademas dejaria lo viejo imprimiendose igual de mal.
     */
    public function test_se_guarda_desde_la_pantalla_aunque_este_publicado(): void
    {
        $plantilla = $this->plantilla(['status' => 'published', 'published_at' => now()]);

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.update', $plantilla->slug), [
                'name'            => $plantilla->name,
                'code'            => $plantilla->code,
                'country_id'      => 1,
                'pdf_orientation' => 'landscape',
            ])
            ->assertRedirect();

        $this->assertSame('landscape', $plantilla->fresh()->pdf_orientation);
    }

    /** Y se puede volver a «automatica», que es vaciarla. */
    public function test_se_puede_volver_a_la_deduccion(): void
    {
        $plantilla = $this->plantilla(['pdf_orientation' => 'landscape']);

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.update', $plantilla->slug), [
                'name'            => $plantilla->name,
                'code'            => $plantilla->code,
                'country_id'      => 1,
                'pdf_orientation' => '',
            ])
            ->assertRedirect();

        $this->assertNull($plantilla->fresh()->pdf_orientation);
    }

    /** Cualquier otra cosa no entra: la columna alimenta a `setPaper()`. */
    public function test_una_orientacion_inventada_no_pasa(): void
    {
        $plantilla = $this->plantilla();

        $this->actingAs($this->admin())
            ->put(route('business_management.form_templates.update', $plantilla->slug), [
                'name'            => $plantilla->name,
                'code'            => $plantilla->code,
                'country_id'      => 1,
                'pdf_orientation' => 'diagonal',
            ])
            ->assertSessionHasErrors('pdf_orientation');

        $this->assertNull($plantilla->fresh()->pdf_orientation);
    }

    /** Los cuatro de obra vienen sembrados con la suya, no deducida. */
    public function test_los_cuatro_formatos_sembrados_traen_su_orientacion(): void
    {
        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);

        foreach (['AST', 'PTF', 'EPP', 'IHM'] as $codigo) {
            $this->assertSame('landscape',
                FormTemplate::where('code', $codigo)->value('pdf_orientation'),
                "el {$codigo} es una cuadricula ancha: en vertical no se lee");
        }
    }

    // ── Decorado ────────────────────────────────────────────────────────

    private function admin(): User
    {
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole('admin');
        $u->givePermissionTo(Permission::all());

        return $u;
    }

    private function plantilla(array $extra = []): FormTemplate
    {
        return FormTemplate::create(array_merge([
            'slug' => Str::random(22), 'country_id' => 1, 'tenant_id' => 1, 'created_by' => 1,
            'code' => 'AST', 'name' => 'Analisis de Seguridad', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'draft', 'version' => 1,
        ], $extra));
    }

    private function conMatrizDeRiesgo(FormTemplate $plantilla): void
    {
        $this->campo($plantilla, 'matriz_de_riesgo', 'risk_matrix');
    }

    /** Ancha de verdad, pero sin matriz: el caso que la regla vieja fallaba. */
    private function conCuadricula(FormTemplate $plantilla): void
    {
        $this->campo($plantilla, 'epp_por_trabajador', 'person_checklist');
    }

    private function campo(FormTemplate $plantilla, string $codigo, string $tipo): void
    {
        $seccion = FormSection::create([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id, 'position' => 0,
            'name_es' => 'Seccion', 'name_en' => 'Section',
        ]);

        FormField::create([
            'slug' => Str::random(22), 'form_section_id' => $seccion->id, 'code' => $codigo,
            'field_type' => $tipo, 'position' => 0, 'is_required' => false, 'config' => [],
        ]);
    }

    /**
     * Lo que decidiria el generador para una entrega de esa plantilla.
     *
     * `orientacion()` es protegido y no hace falta abrirlo: es un detalle del
     * servicio, y lo que se prueba es la decision, no la firma. La entrega no se
     * guarda —solo se le cuelga la plantilla— porque la decision solo mira ahi.
     */
    private function orientacionDe(FormTemplate $plantilla): string
    {
        $entrega = new FormSubmission();
        $entrega->setRelation('formTemplate', $plantilla);

        $metodo = new \ReflectionMethod(FormSubmissionPdfService::class, 'orientacion');
        $metodo->setAccessible(true);

        return $metodo->invoke(app(FormSubmissionPdfService::class), $entrega);
    }
}
