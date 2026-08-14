<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Models\FormTemplate;
use App\Models\WorkType;
use App\Services\FieldWork\FormTemplateBuilder;
use Database\Seeders\FormatosAdicionalesSeeder;
use Database\Seeders\FormTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los formatos adicionales del cliente entran al sembrar, enteros y usables.
 *
 * Son los cinco permisos de trabajo (altura, espacios confinados, electrical
 * safety, pruebas electricas, izaje) y las tres inspecciones (arnes, kit de
 * revelado, elementos de izaje) que el dueño del producto entrego en sus Excel
 * y PDF originales, con la orden de incorporarlos «como adicionales» y
 * añadirlos al catalogo de tipos de trabajo.
 *
 * Lo que se fija aqui es lo mismo que con los cuatro de la v1 —publicados, con
 * campos, idempotentes, configs que pasan el contrato del constructor— mas lo
 * propio de estos: los grupos del Kit con su foto, las preguntas repetidas
 * entre grupos desambiguadas, los B/D del izaje con su tono declarado, y los
 * tipos de trabajo nuevos con su matriz de documentos.
 */
class FormatosAdicionalesTest extends TestCase
{
    use RefreshDatabase;

    private const CODIGOS = ['PTW-ALT', 'PTW-CON', 'PTW-ELE', 'PTW-PRU', 'PTW-IZA', 'IAR', 'IKR', 'IEI'];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    /** Los cuatro de base primero: la matriz de tipos de trabajo los referencia. */
    private function sembrar(): void
    {
        $this->seed(FormTemplatesSeeder::class);
        $this->seed(FormatosAdicionalesSeeder::class);
    }

    public function test_siembra_los_ocho_publicados_con_secciones_y_campos(): void
    {
        $this->sembrar();

        foreach (self::CODIGOS as $codigo) {
            $plantilla = FormTemplate::where('code', $codigo)->first();

            $this->assertNotNull($plantilla, "Falta el formato {$codigo}.");
            $this->assertSame('published', $plantilla->status, "{$codigo} no quedo publicado.");
            $this->assertNotEmpty($plantilla->name_es, "{$codigo} sin nombre en castellano.");
            $this->assertNotEmpty($plantilla->name_en, "{$codigo} sin nombre en ingles.");
            $this->assertGreaterThan(0, $plantilla->sections()->count(), "{$codigo} sin secciones.");
            $this->assertGreaterThan(0, $plantilla->fields()->count(), "{$codigo} sin campos.");
        }
    }

    /** La misma regla que el constructor aplica desde la pantalla. */
    public function test_cada_campo_trae_la_config_que_su_tipo_exige(): void
    {
        $this->sembrar();

        $campos = FormField::whereHas('section.formTemplate',
            fn ($q) => $q->whereIn('code', self::CODIGOS))->get();

        $this->assertNotEmpty($campos);

        foreach ($campos as $campo) {
            $this->assertContains($campo->field_type, array_keys(FormTemplateBuilder::TIPOS),
                "Tipo desconocido: {$campo->field_type}");

            if (! in_array($campo->field_type, FormTemplateBuilder::CONFIG_OBLIGATORIA, true)) {
                continue;
            }

            $faltan = array_diff(
                FormTemplateBuilder::TIPOS[$campo->field_type],
                array_keys($campo->config ?? []),
            );

            $this->assertSame([], array_values($faltan),
                "Al campo {$campo->code} ({$campo->field_type}) le falta config: " . implode(', ', $faltan));
        }
    }

    public function test_sembrar_dos_veces_no_duplica_nada(): void
    {
        $this->sembrar();

        $plantillas = FormTemplate::count();
        $campos = FormField::count();
        $tipos = WorkType::count();
        $pivotes = DB::table('work_type_form_templates')->count();

        $this->seed(FormatosAdicionalesSeeder::class);

        $this->assertSame($plantillas, FormTemplate::count());
        $this->assertSame($campos, FormField::count());
        $this->assertSame($tipos, WorkType::count());
        $this->assertSame($pivotes, DB::table('work_type_form_templates')->count());
    }

    /**
     * El Kit de Revelado: ocho grupos, cada uno con la foto de su equipo.
     *
     * Y las preguntas repetidas entre grupos —«Estado de las costuras» esta en
     * el traje y en la capucha; las dos clases de guante comparten sus seis
     * puntos— desambiguadas, porque la identidad de una pregunta es su VALOR y
     * dos grupos no pueden reclamar el mismo.
     */
    public function test_el_kit_trae_ocho_grupos_con_su_foto_y_sin_valores_repetidos(): void
    {
        $this->sembrar();

        $config = $this->campo('IKR', 'verificacion')->config;

        $this->assertCount(8, $config['groups']);

        foreach ($config['groups'] as $grupo) {
            $this->assertStringStartsWith('data:image/', (string) $grupo['image'],
                "El grupo «{$grupo['name']['es']}» se quedo sin la foto de su equipo.");
            $this->assertNotEmpty($grupo['items']);
        }

        $preguntas = \App\Support\Catalogo::valores($config['questions']);

        $this->assertSame($preguntas, array_unique($preguntas),
            'dos grupos reclaman la misma pregunta: la identidad es el valor');

        // Y todo item de un grupo existe en el catalogo plano.
        foreach ($config['groups'] as $grupo) {
            foreach ($grupo['items'] as $item) {
                $this->assertContains($item, $preguntas);
            }
        }
    }

    /**
     * El izaje responde B/D: valores cortos para la cuadricula, el rotulo
     * entero del papel como etiqueta, y el tono declarado — nada que deducir
     * del castellano.
     */
    public function test_el_izaje_declara_sus_b_y_d(): void
    {
        $this->sembrar();

        $config = $this->campo('IEI', 'eslingas')->config;
        $respuestas = \App\Support\Catalogo::entradas($config['answers']);

        $this->assertSame(['B', 'D'], array_column($respuestas, 'value'));
        $this->assertSame(['ok', 'bad'], array_column($respuestas, 'tone'));

        // El rotulo en castellano es el del papel; `entradas()` resuelve al
        // idioma de la peticion, asi que se pide el de 'es' explicito.
        $this->assertStringContainsString('Buen estado', \App\Support\Catalogo::etiqueta($config['answers'], 'B', 'es'));
        $this->assertStringContainsString('Defectuoso', \App\Support\Catalogo::etiqueta($config['answers'], 'D', 'es'));
    }

    /**
     * Los tipos de trabajo nuevos, con su matriz: los cuatro de base primero y
     * despues los propios, todos obligatorios.
     */
    public function test_los_tipos_de_trabajo_llevan_su_matriz_de_documentos(): void
    {
        $this->sembrar();

        $esperados = [
            'ALTURA'    => ['AST', 'PTF', 'EPP', 'IHM', 'PTW-ALT', 'IAR'],
            'ELECTRICO' => ['AST', 'PTF', 'EPP', 'IHM', 'PTW-ELE', 'PTW-PRU', 'IKR'],
            'CONFINADO' => ['AST', 'PTF', 'EPP', 'IHM', 'PTW-CON'],
            'IZAJE'     => ['AST', 'PTF', 'EPP', 'IHM', 'PTW-IZA', 'IEI'],
        ];

        foreach ($esperados as $codigo => $documentos) {
            $tipo = WorkType::where('code', $codigo)->first();

            $this->assertNotNull($tipo, "Falta el tipo de trabajo {$codigo}.");

            $matriz = DB::table('work_type_form_templates')
                ->join('form_templates', 'form_templates.id', '=', 'work_type_form_templates.form_template_id')
                ->where('work_type_id', $tipo->id)
                ->orderBy('work_type_form_templates.id')
                ->get(['form_templates.code', 'work_type_form_templates.is_required']);

            $this->assertSame($documentos, $matriz->pluck('code')->all(),
                "La matriz de {$codigo} no es la esperada (o no esta en orden).");
            $this->assertTrue($matriz->every(fn ($f) => (bool) $f->is_required),
                "En {$codigo} hay documentos no obligatorios y todos deben serlo.");
        }
    }

    /** Los textos van verbatim, erratas del papel incluidas. */
    public function test_los_textos_del_papel_van_verbatim(): void
    {
        $this->sembrar();

        // La errata mas visible de cada fuente, como testigo de que no se
        // «corrigio» la transcripcion.
        $this->assertContains(
            '¿Absorbedor de impactio no roto?',
            \App\Support\Catalogo::valores($this->campo('IAR', 'linea_anclaje')->config['items']),
        );

        $this->assertContains(
            'Comunicacion con supervisor responsable',
            \App\Support\Catalogo::valores($this->campo('PTW-IZA', 'medidas_de_control')->config['questions']),
        );
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    private function campo(string $codigo, string $campo): FormField
    {
        return FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', $codigo))
            ->where('code', $campo)
            ->firstOrFail();
    }
}
