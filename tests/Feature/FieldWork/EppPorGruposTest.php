<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Services\FieldWork\Pdf\PersonChecklistPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El EPP va agrupado por parte del cuerpo. Y solo el EPP.
 *
 * En el sistema anterior los equipos de proteccion colgaban de
 * `epp_categories`: cabeza, cara, cuerpo, ojos, manos, oidos, vias
 * respiratorias, pies. Es la unica de las cuatro listas del sistema que se
 * agrupa, y no es adorno: veinticinco casillas seguidas se recorren leyendo una
 * a una, y agrupadas se recorren mirando la parte del cuerpo que interesa —que
 * ademas es como uno se revisa a si mismo, de arriba abajo.
 *
 * LO QUE ESTA PRUEBA PROTEGE
 * --------------------------
 * La decision de diseño es que **la agrupacion es una vista sobre `items`, no
 * otra lista**. `config.items` sigue siendo el catalogo —es lo que se guarda en
 * cada respuesta, lo que alinea las columnas del PDF y contra lo que casa la
 * migracion— y `config.groups` solo dice bajo que rotulo va cada uno.
 *
 * Esa decision se rompe sola en cuanto alguien edita una de las dos cosas y no
 * la otra, y la forma de romperse es la peor: un equipo desaparece del reparto
 * y nadie se entera hasta que un inspector pregunta por que en el papel hay
 * veinticuatro columnas. Por eso se comprueba que lo que sobra sale igual y que
 * lo que no existe se ignora.
 */
class EppPorGruposTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El decorado minimo del seeder: sin pais no siembra nada y avisa.
     *
     * Se hace a mano y no con los seeders de catalogos porque lo unico que hace
     * falta aqui es que exista un pais al que colgar los cuatro formatos.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\DB::table('languages')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Spanish', 'iso_code' => 'es', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('locales')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'code' => 'es_AR', 'name' => 'Español', 'language_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('regions')->insertOrIgnore([['id' => 999, 'slug' => Str::random(22), 'name' => '__bs__', 'is_active' => false, 'deleted_at' => now(), 'deleted_description' => 'bs', 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('countries')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'region_id' => 999, 'name' => 'Peru', 'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
        \Illuminate\Support\Facades\DB::table('tenants')->insertOrIgnore([['id' => 1, 'slug' => Str::random(22), 'name' => 'Empresa 1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]]);
    }

    /** Cada grupo cubre sus columnas, y en el orden en que se declara. */
    public function test_las_columnas_van_por_grupo_y_en_su_orden(): void
    {
        $datos = $this->datos(
            ['Casco', 'Lentes', 'Guantes de cuero', 'Botines'],
            [
                ['name' => 'Cabeza', 'items' => ['Casco']],
                ['name' => 'Ojos',   'items' => ['Lentes']],
                ['name' => 'Manos',  'items' => ['Guantes de cuero']],
                ['name' => 'Pies',   'items' => ['Botines']],
            ],
        );

        $this->assertSame(
            ['Cabeza', 'Ojos', 'Manos', 'Pies'],
            array_column($datos['grupos'], 'nombre'),
        );

        $this->assertSame([1, 1, 1, 1], array_column($datos['grupos'], 'columnas'));

        // Y la suma de columnas de los grupos es exactamente la cuadricula: si
        // no cuadra, el `colspan` desplaza la cabecera y los rotulos acaban
        // encima de la columna equivocada, que es peor que no tenerlos.
        $this->assertSame(
            count($datos['items']),
            array_sum(array_column($datos['grupos'], 'columnas')),
        );
    }

    /**
     * Las columnas se REORDENAN para que cada rotulo cubra columnas seguidas.
     *
     * El catalogo puede tener los equipos en otro orden —o alguien puede mover
     * un grupo desde el editor— y un grupo partido en dos trozos no se puede
     * pintar con un `colspan`.
     */
    public function test_manda_el_orden_de_los_grupos_y_no_el_del_catalogo(): void
    {
        $datos = $this->datos(
            ['Casco', 'Guantes', 'Barbiquejo'],
            [
                ['name' => 'Cabeza', 'items' => ['Casco', 'Barbiquejo']],
                ['name' => 'Manos',  'items' => ['Guantes']],
            ],
        );

        $this->assertSame(
            ['Casco', 'Barbiquejo', 'Guantes'],
            array_column($datos['items'], 'texto'),
        );

        $this->assertSame([2, 1], array_column($datos['grupos'], 'columnas'));
    }

    /**
     * Un equipo que no esta en ningun grupo sale igual, al final.
     *
     * Es el caso de alguien que añade un equipo al catalogo y se olvida de
     * meterlo en un grupo. Que desapareciera del papel seria perder una
     * pregunta de una inspeccion de seguridad por un descuido de configuracion.
     */
    public function test_lo_que_no_esta_en_ningun_grupo_no_se_pierde(): void
    {
        $datos = $this->datos(
            ['Casco', 'Arnes'],
            [['name' => 'Cabeza', 'items' => ['Casco']]],
        );

        $this->assertSame(['Casco', 'Arnes'], array_column($datos['items'], 'texto'));

        // Sale en un grupo sin rotulo al final, no dentro del ultimo que habia.
        $this->assertSame(['Cabeza', null], array_column($datos['grupos'], 'nombre'));
    }

    /** Un grupo que nombra un equipo que ya no existe no inventa una columna. */
    public function test_un_grupo_que_nombra_lo_que_no_existe_no_inventa_columnas(): void
    {
        $datos = $this->datos(
            ['Casco'],
            [['name' => 'Cabeza', 'items' => ['Casco', 'Casco de minero que se borro']]],
        );

        $this->assertSame(['Casco'], array_column($datos['items'], 'texto'));
        $this->assertSame([1], array_column($datos['grupos'], 'columnas'));
    }

    /** Y el mismo equipo en dos grupos se queda en el primero. */
    public function test_un_equipo_repetido_no_pide_dos_veces_lo_mismo(): void
    {
        $datos = $this->datos(
            ['Casco', 'Lentes'],
            [
                ['name' => 'Cabeza', 'items' => ['Casco', 'Lentes']],
                ['name' => 'Ojos',   'items' => ['Lentes']],
            ],
        );

        $this->assertSame(['Casco', 'Lentes'], array_column($datos['items'], 'texto'));
        $this->assertSame([2], array_column($datos['grupos'], 'columnas'));
        $this->assertSame(['Cabeza'], array_column($datos['grupos'], 'nombre'));
    }

    /**
     * Sin grupos, la cabecera se queda como estaba.
     *
     * Es lo que hace que esto solo toque al EPP: los otros checklists —y el EPP
     * de un cliente que no quiera grupos— no pintan una fila de rotulos vacia.
     */
    public function test_sin_grupos_no_hay_fila_de_rotulos(): void
    {
        $datos = $this->datos(['Casco', 'Lentes'], null);

        $this->assertSame([], $datos['grupos']);
        $this->assertSame(['Casco', 'Lentes'], array_column($datos['items'], 'texto'));
    }

    /**
     * El EPP sembrado reparte los veinticinco equipos, sin dejarse ninguno.
     *
     * Es la prueba que se rompe si alguien toca el catalogo del JSON y no los
     * grupos: la cuadricula seguiria saliendo, pero con equipos sueltos al
     * final en vez de bajo su parte del cuerpo.
     */
    public function test_el_epp_sembrado_reparte_todos_sus_equipos(): void
    {
        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);

        $campo = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'EPP'))
            ->where('field_type', 'person_checklist')
            ->firstOrFail();

        $config = $campo->config;

        $this->assertNotEmpty($config['groups'] ?? [], 'el EPP se siembra agrupado por parte del cuerpo');

        $enGrupos = array_merge(...array_column($config['groups'], 'items'));

        $this->assertSame(
            [],
            array_diff($config['items'], $enGrupos),
            'hay equipos del catalogo que no estan en ningun grupo',
        );
        $this->assertSame(
            [],
            array_diff($enGrupos, $config['items']),
            'hay grupos que nombran equipos que no estan en el catalogo',
        );
        $this->assertSame(count($enGrupos), count(array_unique($enGrupos)),
            'un equipo en dos grupos pediria dos veces la misma casilla');
    }

    /** Y el IHM NO se agrupa: es el otro checklist y el papel no lo agrupaba. */
    public function test_el_ihm_no_lleva_grupos(): void
    {
        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);

        $campo = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', 'IHM'))
            ->where('field_type', 'tool_checklist')
            ->firstOrFail();

        $this->assertArrayNotHasKey('groups', $campo->config ?? []);
    }

    /**
     * La pantalla de llenado reparte con la MISMA regla que el papel.
     *
     * No hay forma de probar el componente de verdad —no hay navegador en la
     * suite— pero si de comprobar que lee `config.groups` en vez de pintar la
     * lista plana. Si el papel agrupa y la tablet no, no se pueden cotejar, que
     * es justo para lo que se imprime este documento.
     */
    public function test_la_pantalla_de_llenado_tambien_agrupa(): void
    {
        $vista = file_get_contents(resource_path('js/Components/FormFields/PersonChecklistField.vue'));

        $this->assertStringContainsString('agrupar(items.value, config.value?.groups)', $vista);
        $this->assertStringContainsString('v-for="grupo in grupos"', $vista);
    }

    /**
     * El editor de formatos no destroza los grupos al guardar.
     *
     * Todas las claves de configuracion caen por defecto en el editor de listas
     * de textos, y los grupos no son textos: pasarlos por ahi los guardaria
     * como «[object Object]» y el reparto se perderia al primer guardado desde
     * la pantalla, en silencio. Por eso `groups` tiene su propio control.
     */
    public function test_el_editor_tiene_un_control_propio_para_los_grupos(): void
    {
        $controlador = new \App\Http\Controllers\BusinessManagement\FormTemplateController();

        $metodo = new \ReflectionMethod($controlador, 'controlDeClave');
        $metodo->setAccessible(true);

        $this->assertSame('groups', $metodo->invoke($controlador, 'groups'));
        $this->assertSame('list', $metodo->invoke($controlador, 'items'));

        $editor = file_get_contents(resource_path('js/Components/FormTemplates/FieldConfigEditor.vue'));

        $this->assertStringContainsString("item.control === 'groups'", $editor);
    }

    // ── Decorado ────────────────────────────────────────────────────────

    /**
     * `PersonChecklistPdf::datos()` de un campo con ese catalogo y esos grupos.
     *
     * Sin respuestas: lo que se mira aqui son las COLUMNAS, que salen del
     * catalogo y no de lo que nadie haya contestado.
     */
    private function datos(array $items, ?array $grupos): array
    {
        $config = ['items' => $items, 'answers' => ['Conforme', 'No aplica', 'No conforme']];

        if ($grupos !== null) {
            $config['groups'] = $grupos;
        }

        $campo = new FormField([
            'slug' => Str::random(22), 'code' => 'epp', 'field_type' => 'person_checklist',
            'position' => 0, 'is_required' => false, 'config' => $config,
        ]);

        return PersonChecklistPdf::datos($campo, new Collection());
    }
}
