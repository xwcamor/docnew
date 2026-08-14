<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\WorkPlan;
use App\Services\FieldWork\Pdf\QuestionBankPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El Pare y Tome 5 va por bloques, como su papel: titulo, icono y sus preguntas.
 *
 * DE DONDE VIENE
 * --------------
 * El papel de la v1 no es una lista corrida de diecisiete preguntas: son cinco
 * bloques numerados —«1. ¡DETENTE y piensa antes de actuar!», «2. ¿Somos
 * COMPETENTES...?»— cada uno con su dibujo al lado (el semaforo, la cabeza, la
 * lupa). El dueño del producto pidio poder reproducir eso SIN mandrakear, y
 * escalable: «hay futuros modulos que usan imagenes y agrupan cosas».
 *
 * LA FORMA: la misma `groups` del EPP —una vista sobre el catalogo, nunca otra
 * lista— con una imagen opcional por grupo. La imagen viaja como data URI
 * dentro de la config: sin almacen de archivos, se copia sola con cada version
 * del formato (lo firmado con la v1 se imprime con el icono de la v1) y DomPDF
 * la pinta sin salir a la red.
 */
class PtfPorBloquesTest extends TestCase
{
    use RefreshDatabase;

    /** Un PNG de 1x1, que es todo lo que un icono necesita ser en una prueba. */
    private const ICONO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    /**
     * El PDF reparte por bloques y no pierde nada por el camino.
     *
     * La regla es la del EPP: el primer grupo que reclama una pregunta se la
     * queda, y lo que ningun grupo reclama sale AL FINAL, sin rotulo — nunca
     * fuera del papel. Los grupos llevan INDICES de la lista numerada, asi que
     * el resumen («2 observaciones — preguntas 7, 12») sigue apuntando bien.
     */
    public function test_el_pdf_reparte_las_preguntas_en_sus_bloques(): void
    {
        $campo = $this->campo([
            'questions' => ['¿Permisos?', '¿Competente?', '¿Equipado?'],
            'answers'   => ['Si', 'No'],
            'groups'    => [
                ['name' => '1. ¡DETÉNTE!', 'items' => ['¿Permisos?'], 'image' => self::ICONO],
                ['name' => '2. ¿Competentes?', 'items' => ['¿Competente?']],
            ],
        ]);

        $datos = QuestionBankPdf::datos($campo, collect());

        $this->assertCount(3, $datos['grupos'], 'dos bloques declarados y el de lo suelto');

        [$primero, $segundo, $sueltos] = $datos['grupos'];

        $this->assertSame('1. ¡DETÉNTE!', $primero['titulo']);
        $this->assertSame(self::ICONO, $primero['image']);
        $this->assertNull($segundo['image'], 'un grupo sin icono es un grupo normal');
        $this->assertNull($sueltos['titulo'], 'lo que ningun grupo reclamo sale al final y sin rotulo');

        // Y entre todos cubren la lista entera, sin repetir ninguna.
        $indices = array_merge(...array_column($datos['grupos'], 'indices'));
        sort($indices);
        $this->assertSame(array_keys($datos['preguntas']), $indices);
    }

    /** Sin grupos, un solo bloque anonimo con todo: como se pintaba siempre. */
    public function test_sin_grupos_el_pdf_sale_como_salia(): void
    {
        $campo = $this->campo(['questions' => ['¿A?', '¿B?'], 'answers' => ['Si', 'No']]);

        $datos = QuestionBankPdf::datos($campo, collect());

        $this->assertCount(1, $datos['grupos']);
        $this->assertNull($datos['grupos'][0]['titulo']);
    }

    /**
     * Solo un data URI puede ser el icono.
     *
     * Una URL externa obligaria a DomPDF a salir a la red para imprimir un
     * documento de seguridad — y de paso contaria a un tercero cuando se
     * imprime. Se descarta al leer, no al guardar: tambien protege de lo que
     * alguien meta por la API.
     */
    public function test_una_url_externa_no_es_un_icono(): void
    {
        $campo = $this->campo([
            'questions' => ['¿A?'],
            'answers'   => ['Si', 'No'],
            'groups'    => [['name' => 'Bloque', 'items' => ['¿A?'], 'image' => 'https://fuera.com/x.png']],
        ]);

        $this->assertNull(QuestionBankPdf::datos($campo, collect())['grupos'][0]['image']);
    }

    /**
     * La pantalla reparte con la MISMA regla, y el editor lo ofrece.
     *
     * Sin navegador en la suite, se lee el codigo como texto — el mismo recurso
     * que `EppPorGruposTest`.
     */
    public function test_la_pantalla_y_el_editor_van_por_bloques(): void
    {
        $ptf = file_get_contents(resource_path('js/Components/FormFields/QuestionBankField.vue'));

        $this->assertStringContainsString('agrupar(preguntas.value, config.value?.groups, locale.value)', $ptf,
            'la pantalla reparte con la misma agrupar() del EPP');
        $this->assertStringContainsString('v-for="(grupo, g) in grupos"', $ptf);
        $this->assertStringContainsString('grupo.image', $ptf, 'el icono del bloque tambien se ve al llenar');

        // El editor ofrece los grupos para el banco de preguntas...
        $controlador = file_get_contents(app_path('Http/Controllers/BusinessManagement/FormTemplateController.php'));
        $this->assertMatchesRegularExpression("/'question_bank'\s*=>\s*\['answers', 'groups'\]/", $controlador);

        // ...con la imagen reducida ANTES de guardarse: un data URI en la
        // config no puede ser la foto de 4MB de una camara.
        $editor = file_get_contents(resource_path('js/Components/FormTemplates/GroupListEditor.vue'));
        $this->assertStringContainsString('comoIcono', $editor);
        $this->assertStringContainsString("toDataURL('image/png')", $editor);
        $this->assertStringContainsString('const LADO = 96', $editor);
    }

    /**
     * EL PTF SEMBRADO trae los cinco bloques del papel, con sus iconos reales.
     *
     * Es lo que el dueño del producto pidio con la foto del papel en la mano:
     * no solo el MECANISMO de los bloques, sino EL PTF armado como su papel.
     * Los titulos (en tres idiomas) y el reparto vienen de
     * `ptf_question_titles` / `ptf_questions` de la v1, y los iconos son los
     * PNG reales de alla (el semaforo, la cabeza, las herramientas, la lupa,
     * el pulgar), como data URI.
     *
     * Y llega tambien al PTF que YA estaba sembrado, por la reparacion
     * aditiva del seeder — sin pisar unos grupos que un cliente hubiera
     * armado a mano.
     */
    public function test_el_ptf_sembrado_trae_los_cinco_bloques_del_papel(): void
    {
        \Illuminate\Support\Facades\DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_PE', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);

        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);

        $campo = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'PTF'))
            ->where('field_type', 'question_bank')
            ->firstOrFail();

        $grupos = $campo->config['groups'] ?? [];

        $this->assertCount(5, $grupos, 'los cinco bloques del papel de la v1');

        // El primero, tal cual el papel: titulo en tres idiomas y su semaforo.
        $this->assertSame('1. ¡DETÉNTE y piensa antes de actuar!', $grupos[0]['name']['es']);
        $this->assertSame('1. STOP and think before acting!', $grupos[0]['name']['en']);
        $this->assertArrayHasKey('pt', $grupos[0]['name'],
            'el portugues de la v1 viaja tambien: es la prueba viva de los idiomas futuros');
        $this->assertStringStartsWith('data:image/png;base64,', $grupos[0]['image']);

        // El reparto cubre las 17 preguntas del catalogo, sin perder ninguna.
        $repartidas = array_merge(...array_column($grupos, 'items'));
        $this->assertEqualsCanonicalizing($campo->config['questions'], $repartidas);

        // Y volver a sembrar NO duplica ni pisa: la reparacion es aditiva.
        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);
        $this->assertCount(5, $campo->fresh()->config['groups']);
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    private function campo(array $config): FormField
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
            'code' => 'PTF', 'kind' => FormTemplate::STRUCTURED, 'status' => 'published',
            'version' => 1, 'requires_signature' => false, 'published_at' => now(), 'is_active' => true,
        ]);

        $seccion = FormSection::create([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'code' => 'preguntas', 'title' => 'Preguntas', 'position' => 1,
        ]);

        return FormField::create([
            'slug' => Str::random(22), 'form_section_id' => $seccion->id,
            'code' => 'preguntas', 'field_type' => 'question_bank',
            'is_required' => true, 'position' => 1, 'config' => $config,
        ]);
    }
}
