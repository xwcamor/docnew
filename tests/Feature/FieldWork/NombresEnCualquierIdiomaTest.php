<?php

namespace Tests\Feature\FieldWork;

use App\Models\ApproverRole;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Support\TextoTraducible;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los nombres que escribe el cliente se leen en cualquier idioma, no solo en dos.
 *
 * EL ULTIMO CABO DEL «FUTUROS IDIOMAS»
 * ------------------------------------
 * Todo lo demas ya escalaba: los textos del producto con `lang/{idioma}`, los
 * catalogos de los formatos con el mapa `{es, en, pt…}`. Pero el nombre de un
 * campo, el titulo de una seccion, el nombre del formato y el del rol aprobador
 * vivian en parejas de columnas fijas — el texto MAS visible de un formato era
 * el unico atado a exactamente dos idiomas, y el tercero pedia migracion.
 *
 * LA REGLA QUE ESTAS PRUEBAS FIJAN: cada idioma tiene UN solo sitio donde
 * vivir. El es y el en, en sus columnas de siempre —ni un dato existente se
 * toca, y los exports y filtros que las leen en crudo siguen igual—; cualquier
 * otro idioma, en la columna `*_i18n`. El accessor funde las dos fuentes con
 * las columnas mandando en su idioma, para que no pueda existir una copia
 * rancia que gane a la editada.
 */
class NombresEnCualquierIdiomaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    /**
     * Un idioma sin columna se lee desde `*_i18n`, en los cuatro nombres.
     *
     * El portugues de aqui no existe en `lang/`: no hace falta. Lo que se
     * comprueba es que el TEXTO DEL CLIENTE tiene donde vivir y por donde
     * leerse sin que nadie toque una tabla.
     */
    public function test_el_tercer_idioma_se_lee_sin_tocar_el_esquema(): void
    {
        [$plantilla, $seccion, $campo] = $this->formato();

        $plantilla->update(['name_i18n' => ['pt' => 'Inspeção de EPI']]);
        $seccion->update(['name_i18n' => ['pt' => 'Geral']]);
        $campo->update(['label_i18n' => ['pt' => 'EPI por trabalhador']]);

        $rol = ApproverRole::create([
            'slug' => Str::random(22), 'code' => 'sup', 'name_es' => 'Supervisor Autorizante', 'name_en' => '',
            'name_i18n' => ['pt' => 'Supervisor Autorizador'], 'is_active' => true,
        ]);

        app()->setLocale('pt');

        try {
            $this->assertSame('Inspeção de EPI', $plantilla->fresh()->label);
            $this->assertSame('Geral', $seccion->fresh()->label);
            $this->assertSame('EPI por trabalhador', $campo->fresh()->label);
            $this->assertSame('Supervisor Autorizador', $rol->fresh()->label);
        } finally {
            app()->setLocale('es');
        }
    }

    /** Sin traduccion no hay hueco: cae al respaldo, que dice mas que un blanco. */
    public function test_lo_no_traducido_cae_al_idioma_que_haya(): void
    {
        [, , $campo] = $this->formato();

        app()->setLocale('pt');

        try {
            $this->assertSame('EPP por trabajador', $campo->fresh()->label,
                'sin pt, el nombre en castellano dice mas que un hueco');
        } finally {
            app()->setLocale('es');
        }
    }

    /**
     * LA COLUMNA MANDA EN SU IDIOMA: un `es` colado en el JSON no pisa nada.
     *
     * Es la regla anti-copia-rancia. Si el JSON pudiera traer `es`, editar la
     * columna desde la pantalla dejaria viva la copia vieja del JSON — y
     * ganando, porque alguien tendria que decidir cual va primero.
     */
    public function test_un_es_colado_en_el_json_no_gana_a_la_columna(): void
    {
        [, , $campo] = $this->formato();

        $campo->update(['label_i18n' => ['es' => 'COPIA RANCIA', 'pt' => 'EPI']]);

        $this->assertSame('EPP por trabajador', $campo->fresh()->label);
    }

    /**
     * El editor de estructura escribe el idioma en curso DONDE le toca.
     *
     * En es/en, a la columna de siempre —los exports y filtros las leen en
     * crudo—; en cualquier otro, a `*_i18n`. Y solo ese idioma: quien edita en
     * portugues no puede pisar lo escrito en castellano.
     */
    public function test_el_editor_escribe_cada_idioma_en_su_sitio(): void
    {
        $servicio = file_get_contents(app_path('Services/BusinessManagement/FormTemplateStructureService.php'));

        $this->assertStringContainsString("in_array(\$idioma, ['es', 'en'], true)", $servicio);
        $this->assertStringContainsString("\$modelo->{\$base . '_i18n'}", $servicio);
        $this->assertStringNotContainsString('columnaDelIdioma', $servicio,
            'la escritura ya no esta atada a dos columnas');
    }

    /** `fundir()`, la pieza: columnas mas JSON, con las columnas mandando. */
    public function test_fundir_junta_las_dos_fuentes(): void
    {
        $mapa = TextoTraducible::fundir(
            ['es' => 'Casco', 'en' => null],
            ['en' => 'IGNORADO-hay-columna-en', 'pt' => 'Capacete', 'qq' => '  '],
        );

        // El `en` del JSON se ignora aunque su columna este vacia: cada idioma
        // tiene UN sitio, y el de `en` es la columna.
        $this->assertSame(['es' => 'Casco', 'pt' => 'Capacete'], $mapa);
    }

    // ── Decorado ────────────────────────────────────────────────────────────

    /** @return array{0: FormTemplate, 1: FormSection, 2: FormField} */
    private function formato(): array
    {
        $plantilla = FormTemplate::create([
            'slug' => Str::random(22), 'tenant_id' => 1, 'country_id' => 1,
            'code' => 'EPP', 'name_es' => 'Inspección de EPP', 'kind' => FormTemplate::STRUCTURED,
            'status' => 'published', 'version' => 1, 'requires_signature' => false,
            'published_at' => now(), 'is_active' => true,
        ]);

        $seccion = FormSection::create([
            'slug' => Str::random(22), 'form_template_id' => $plantilla->id,
            'code' => 'general', 'name_es' => 'General', 'position' => 1,
        ]);

        $campo = FormField::create([
            'slug' => Str::random(22), 'form_section_id' => $seccion->id,
            'code' => 'epp_por_trabajador', 'label_es' => 'EPP por trabajador',
            'field_type' => 'person_checklist', 'is_required' => true, 'position' => 1,
            'config' => ['items' => ['Casco'], 'answers' => ['Conforme', 'No conforme']],
        ]);

        return [$plantilla, $seccion, $campo];
    }
}
