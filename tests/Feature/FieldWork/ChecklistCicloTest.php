<?php

namespace Tests\Feature\FieldWork;

use App\Models\FormField;
use App\Services\FieldWork\FormFindingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La casilla de checklist se marca tocandola, y el toque cicla.
 *
 * DE DONDE VIENE
 * --------------
 * Cada punto de inspeccion era una fila con su texto y tres botones al lado
 * —Conforme / No aplica / No conforme—, y con veinte puntos por herramienta y
 * veinticinco equipos por trabajador eso es una lista kilometrica. El dueño del
 * producto lo resumio: «se siente una hoja de papel», «es una tablet, hay que
 * aprovechar la interaccion». Ahora el punto y su respuesta son la MISMA
 * pastilla, las pastillas se reparten el ancho en cuadricula, y el toque las
 * lleva de sin marcar a conforme, de conforme a no conforme y de vuelta.
 *
 * LO QUE ESTA PRUEBA PROTEGE
 * --------------------------
 * No hay navegador en la suite, asi que esto lee los `.vue` y el `.css` como
 * texto —el mismo recurso que `EppPorGruposTest::test_la_pantalla_de_llenado
 * _tambien_agrupa`— y comprueba las decisiones que se rompen solas en cuanto
 * alguien toca el componente sin saber por que estaba asi:
 *
 *   1. «No aplica» NO esta en el ciclo, y no lo esta porque el servidor lo
 *      escribe al cerrar en todo lo que quedo en blanco. Las dos mitades tienen
 *      que seguir de acuerdo: si el servidor dejara de rellenar, el gris
 *      pasaria a significar «sin responder» y el ciclo estaria mintiendo.
 *   2. El rojo lleva la cruz Y la palabra escrita (docs/UI.md §5).
 *   3. El objetivo de toque no baja de 48 px y la cuadricula se adapta sola,
 *      sin media queries: guantes, sol y una tarjeta que mide lo que mide sin
 *      importar el ancho de la ventana.
 *   4. Hay como deshacer un toque de mas, que es la pega conocida del ciclo.
 *   5. Y ninguna de las palabras esta escrita a pelo: salen de `config.answers`
 *      del formato, que en el EPP dice «Conforme» y en el IHM «Cumple».
 */
class ChecklistCicloTest extends TestCase
{
    use RefreshDatabase;

    /** Los dos campos que ciclan. El PTF no esta, y es a proposito. */
    private const CHECKLISTS = ['PersonChecklistField', 'ToolChecklistField'];

    /** El decorado minimo del seeder: sin pais no siembra nada y avisa. */
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
     * Los dos checklists pintan la cuadricula de pastillas, y ninguno de los dos
     * sigue pintando la fila de tres botones.
     *
     * Van juntos a proposito: es la misma interaccion y llevan meses
     * convergiendo —la agrupacion, los simbolos, la cuadricula del PDF—, asi
     * que volver a separarlos es el fallo que hay que impedir.
     */
    public function test_los_dos_checklists_marcan_con_la_pastilla_que_cicla(): void
    {
        foreach (self::CHECKLISTS as $componente) {
            $vista = $this->vista($componente);

            $this->assertStringContainsString('<AnswerCycle', $vista,
                "{$componente} tiene que marcar con la pastilla que cicla");
            $this->assertStringContainsString('class="ff-checks"', $vista,
                "{$componente} tiene que repartir las pastillas en cuadricula");
            $this->assertStringNotContainsString('AnswerToggle', $vista,
                "{$componente} ya no pinta la fila de tres botones al lado del texto");
        }
    }

    /**
     * El PTF tambien cicla, PERO su gris no significa «no aplica».
     *
     * El gesto se aplica a los tres formatos a peticion del dueño del producto:
     * quien llena los cuatro documentos del dia no tiene por que aprender dos
     * maneras de contestar. Lo que NO se puede copiar es el significado.
     *
     * En el EPP y en las herramientas, lo que se deja en blanco lo cierra el
     * servidor como «no aplica» al confirmar. En el PTF no: una pregunta en
     * blanco es una pregunta **sin responder**, el campo es obligatorio y hay
     * que contestarlas todas.
     *
     * Y eso no lo decide la pantalla, lo decide el catalogo: el PTF se siembra
     * con «Si» y «No» y sin ninguna respuesta que signifique «no aplica», asi
     * que `palabrasDelCiclo()` devuelve `na: null` y sale la leyenda que **no
     * promete ningun relleno**. Esta prueba ata las dos mitades — si algun dia
     * el servidor rellenara tambien las preguntas del PTF, o alguien le pusiera
     * un «No aplica» al catalogo, aqui es donde salta.
     */
    public function test_el_ptf_cicla_pero_su_gris_no_es_no_aplica(): void
    {
        $ptf = $this->vista('QuestionBankField');

        $this->assertStringContainsString('<AnswerCycle', $ptf,
            'el PTF se contesta con el mismo gesto que los otros dos');
        $this->assertStringNotContainsString('AnswerToggle', $ptf);

        // La leyenda que sale es la que NO habla de rellenar nada al cerrar.
        $this->assertStringContainsString('field_work.checklist_hint_no_na', $ptf);
        $this->assertStringNotContainsString("\$t('field_work.checklist_hint',", $ptf);

        // Y la mitad del servidor que lo sostiene: el relleno recorre
        // EXACTAMENTE los otros dos tipos de campo, no este.
        $servicio = file_get_contents(app_path('Services/FieldWork/FormSubmissionService.php'));

        $this->assertStringContainsString(
            "whereIn('field_type', ['person_checklist', 'tool_checklist'])",
            $servicio,
            'una pregunta del PTF en blanco es una pregunta sin responder, no un «no aplica»',
        );

        // El catalogo sembrado del PTF no tiene «no aplica», que es lo que hace
        // verdadera la leyenda de arriba.
        $seeder = file_get_contents(database_path('seeders/FormTemplatesSeeder.php'));

        $this->assertStringContainsString("'answers' => ['Si', 'No']", $seeder);
    }

    /**
     * «No aplica» no entra en el ciclo, y el ciclo empieza por el hueco.
     *
     * El orden tambien importa: primero lo que se marca casi siempre y despues
     * la excepcion, para que el caso comun cueste un toque.
     */
    public function test_el_ciclo_es_sin_marcar_conforme_no_conforme(): void
    {
        $js = file_get_contents(resource_path('js/Components/FormFields/respuestas.js'));

        $this->assertStringContainsString('return [null, ok, bad].filter', $js,
            'el ciclo es [sin marcar, positiva, negativa] y no incluye «no aplica»');
        $this->assertStringContainsString('export function siguienteEnCiclo', $js);
        $this->assertStringContainsString('export function palabrasDelCiclo', $js);
    }

    /**
     * Los dos formatos sembrados tienen las tres respuestas que el diseño da por
     * supuestas: una positiva, una negativa y una de «no aplica».
     *
     * Es la unica parte de esto que se puede comprobar EJECUTANDO, y es la que
     * de verdad decide si el ciclo funciona en obra: sin negativa no habria como
     * decir que algo falla, y sin «no aplica» el gris dejaria de valer por una
     * respuesta y el hueco volveria a ser un hueco. La regla que las clasifica
     * es la del servidor (`FormFindingsService::tono()`), que es la misma que
     * usa la pantalla.
     */
    public function test_el_epp_y_el_ihm_traen_las_tres_respuestas_que_el_ciclo_necesita(): void
    {
        $this->seed(\Database\Seeders\FormTemplatesSeeder::class);

        $reglas = app(FormFindingsService::class);

        foreach (['EPP' => 'person_checklist', 'IHM' => 'tool_checklist'] as $codigo => $tipo) {
            $campo = FormField::whereHas('section.formTemplate', fn ($q) => $q->where('code', $codigo))
                ->where('field_type', $tipo)
                ->firstOrFail();

            $tonos = array_map(fn ($r) => $reglas->tono($r), $campo->config['answers'] ?? []);

            $this->assertContains('ok', $tonos, "el {$codigo} necesita una respuesta positiva que marcar");
            $this->assertContains(FormFindingsService::MALA, $tonos, "el {$codigo} necesita poder decir que algo falla");
            $this->assertContains(FormFindingsService::NO_APLICA, $tonos,
                "sin «no aplica» en el catalogo, dejar la casilla en gris deja de ser una respuesta");
        }
    }

    /**
     * La casilla es un `<button>` de verdad, con su etiqueta accesible.
     *
     * Un `div` con un `@click` no lo alcanza el tabulador, no responde al
     * espacio ni al intro y no se anuncia como algo que se pueda pulsar. Y la
     * etiqueta no puede ser solo el nombre del punto: un boton que cambia de
     * significado cada vez que se pulsa tiene que decir en que estado esta y a
     * donde lleva el siguiente toque.
     */
    public function test_la_casilla_es_un_boton_navegable_y_dice_su_estado_en_palabras(): void
    {
        $vista = $this->vista('AnswerCycle');

        $this->assertStringContainsString(":is=\"tocable ? 'button' : 'span'\"", $vista,
            'la casilla que se puede marcar es un <button>; el resto no es un boton y no lo finge');
        $this->assertStringContainsString(':aria-label="tocable ? etiqueta : null"', $vista);
        $this->assertStringContainsString('field_work.checklist_unmarked', $vista,
            'el gris tambien se dice en palabra');
        $this->assertStringContainsString('field_work.checklist_tap_next', $vista,
            'la etiqueta tiene que anunciar a donde lleva el siguiente toque');

        // El foco del teclado se ve: sin esto se puede tabular a ciegas.
        $this->assertMatchesRegularExpression('/\.ff-cycle:focus-visible\s*\{[^}]*outline:/', $this->css());
    }

    /**
     * El rojo lleva la cruz Y la palabra escrita. El verde se queda con la
     * marca.
     *
     * Es la convencion del repo (docs/UI.md §5) leida donde importa: el rojo es
     * el estado que informa —dispara los campos de correccion, cuenta como no
     * conformidad y alguien lo va a leer en una fotocopia en blanco y negro— y
     * ahi la palabra no se negocia. El verde no la lleva porque la pastilla ya
     * es texto (el nombre del punto), porque el ✔ es una marca que no depende
     * del color, y porque repetir «Conforme» veinticinco veces tapa justo la
     * pastilla distinta que hay que encontrar. En solo lectura salen todas: ahi
     * ya no se busca la excepcion, se lee un documento cerrado.
     */
    public function test_el_estado_negativo_nunca_va_solo_de_color(): void
    {
        $vista = $this->vista('AnswerCycle');

        $this->assertStringContainsString(
            "props.readonly || clave.value === 'bad' || clave.value === 'na'",
            $vista,
            'el rojo y el «no aplica» llevan su palabra escrita, y en solo lectura la llevan todos',
        );
        $this->assertStringContainsString('class="ff-cycle__state"', $vista);

        // Y la parte que no es color: la cruz y el ✔ dentro de la marca.
        $this->assertStringContainsString('CloseOutlined', $vista);
        $this->assertStringContainsString('CheckOutlined', $vista);
    }

    /**
     * Ninguna palabra de respuesta escrita a pelo.
     *
     * El EPP dice «Conforme/No conforme» y el IHM «Cumple/No cumple»: son
     * catalogos del formato, y un cliente puede traer los suyos. En cuanto una
     * de las dos palabras se teclea en el componente, ese componente sirve para
     * un formato y miente en el otro.
     */
    public function test_las_respuestas_salen_del_catalogo_y_no_del_codigo(): void
    {
        $prohibidas = ['Conforme', 'No conforme', 'Cumple', 'No cumple', 'No aplica'];

        foreach ([...self::CHECKLISTS, 'AnswerCycle'] as $componente) {
            // Los comentarios NOMBRAN los catalogos para explicar por que no se
            // escriben, y eso es justo lo que hay que conservar.
            $codigo = $this->sinComentarios($this->vista($componente));

            foreach ($prohibidas as $palabra) {
                // Con limites de palabra: `filaConforme()` es el nombre de la
                // funcion que decide si la fila salio limpia, no una respuesta.
                $this->assertDoesNotMatchRegularExpression(
                    '/\b' . preg_quote($palabra, '/') . '\b/',
                    $codigo,
                    "{$componente} escribe «{$palabra}» a pelo: tiene que salir de config.answers",
                );
            }
        }
    }

    /**
     * El objetivo de toque no baja de 48 px, ni el de la casilla ni el de
     * deshacer.
     *
     * Es la medida de quien llena esto: casco, guantes y a pleno sol
     * (docs/UI.md §0). Y por lo mismo no hay nada escondido detras de una
     * pulsacion larga ni en una esquinita del rotulo.
     */
    public function test_la_casilla_se_acierta_con_guantes(): void
    {
        foreach (['.ff-cycle', '.ff-undo'] as $clase) {
            preg_match('/min-height:\s*(\d+)px/', $this->regla($clase), $m);

            $this->assertNotEmpty($m, "{$clase} tiene que declarar su alto minimo");
            $this->assertGreaterThanOrEqual(48, (int) $m[1],
                "{$clase} mide {$m[1]}px y el minimo con guantes es 48");
        }
    }

    /**
     * La cuadricula se adapta sola, sin media queries a mano.
     *
     * La condicion no es «la ventana es chica» sino «aqui no cabe otra
     * columna»: el mismo campo se pinta en una tablet en vertical y en un
     * escritorio de 1440, y con rotulos como «Resp. con filtro para humos» lo
     * que decide es el ancho disponible, no el de la pantalla. Eso es
     * exactamente `auto-fit` + `minmax`, y es la misma leccion que ya esta
     * escrita en `.ff-item__main` con sus container queries.
     */
    public function test_la_cuadricula_se_reparte_sola(): void
    {
        $this->assertMatchesRegularExpression(
            '/grid-template-columns:\s*repeat\(auto-fit,\s*minmax\((?:var\(--ff-check-min,\s*)?[\d.]+rem/',
            $this->regla('.ff-checks'),
            'la cuadricula se reparte con auto-fit, no con una lista de media queries',
        );
    }

    /**
     * Deshacer un toque de mas cuesta UN toque, y esta donde estaba la mano.
     *
     * Es la pega conocida del ciclo: con tres estados, volver al anterior
     * costaria dos toques mas. El boton sale pegado a la pastilla que se acaba
     * de tocar —no en el pie de la tarjeta, a veinte casillas— y hay uno solo en
     * toda la pantalla, para no tener que acertar cual de todos era.
     */
    public function test_hay_como_deshacer_el_toque_de_mas(): void
    {
        $this->assertFileExists(resource_path('js/Components/FormFields/ultimoToque.js'));

        $pastilla = $this->vista('AnswerCycle');

        $this->assertStringContainsString("defineEmits(['update:value', 'deshacer'])", $pastilla);
        $this->assertStringContainsString('field_work.checklist_undo', $pastilla,
            'el boton es solo el simbolo: la palabra vive en su aria-label');

        foreach (self::CHECKLISTS as $componente) {
            $vista = $this->vista($componente);

            $this->assertStringContainsString('useUltimoToque', $vista);
            $this->assertStringContainsString(':deshacible="esUltimo(', $vista,
                "{$componente} solo ofrece deshacer en la casilla que se acaba de tocar");
            $this->assertStringContainsString('@deshacer="deshacer"', $vista);
        }
    }

    /**
     * La leyenda explica el ciclo antes de que nadie toque nada.
     *
     * «Que se entienda sin que nadie lo explique» se consigue explicandolo en la
     * pantalla, en una linea y arriba: que pasa al tocar, que pasa al volver a
     * tocar y como se deshace un toque de mas. Las palabras las pone el catalogo
     * del formato (`:ok`, `:bad`) y la de «no aplica» es la que el servidor va a
     * escribir al cerrar (`:na`).
     */
    public function test_la_leyenda_cuenta_el_ciclo_en_los_dos_idiomas(): void
    {
        foreach (['es', 'en'] as $idioma) {
            $textos = require lang_path("{$idioma}/field_work.php");

            foreach ([':ok', ':bad'] as $hueco) {
                $this->assertStringContainsString($hueco, $textos['checklist_hint'],
                    "la leyenda en {$idioma} tiene que nombrar las respuestas del catalogo");
                $this->assertStringContainsString($hueco, $textos['checklist_hint_no_na']);
            }

            $this->assertStringContainsString(':na', $textos['checklist_hint'],
                'la leyenda tiene que decir la palabra que el servidor va a escribir al cerrar');
            $this->assertStringNotContainsString(':na', $textos['checklist_hint_no_na'],
                'sin «no aplica» en el catalogo el servidor no rellena nada, y prometerlo seria mentir');

            $this->assertNotEmpty($textos['checklist_unmarked']);
            $this->assertStringContainsString(':next', $textos['checklist_tap_next']);
            $this->assertStringContainsString(':item', $textos['checklist_undo']);
        }

        // Y la pantalla elige la leyenda que corresponde a SU catalogo.
        foreach (self::CHECKLISTS as $componente) {
            $vista = $this->vista($componente);

            $this->assertStringContainsString('field_work.checklist_hint_no_na', $vista);
            $this->assertStringContainsString('palabrasDelCiclo', $vista);
        }
    }

    /**
     * El EPP sigue agrupado por parte del cuerpo: una cuadricula POR grupo.
     *
     * La cuadricula podria haberse comido los rotulos —veinticinco pastillas
     * seguidas caben igual— y eso desharia lo que los grupos vinieron a
     * arreglar: un cuadro corrido se recorre leyendo una a una, y por partes se
     * recorre mirando la que interesa, que ademas es como uno se revisa a si
     * mismo, de arriba abajo. Ver `EppPorGruposTest`.
     */
    public function test_la_cuadricula_del_epp_se_corta_en_cada_parte_del_cuerpo(): void
    {
        $vista = $this->vista('PersonChecklistField');

        $this->assertStringContainsString('v-for="grupo in grupos"', $vista);
        $this->assertSame(1, substr_count($vista, 'class="ff-checks"'),
            'hay una sola cuadricula escrita y va DENTRO del bucle de grupos: una por parte del cuerpo');
        $this->assertLessThan(
            strpos($vista, 'class="ff-checks"'),
            strpos($vista, 'v-for="grupo in grupos"'),
            'la cuadricula tiene que estar dentro del grupo, no antes',
        );
        $this->assertStringContainsString('v-for="item in grupo.items"', $vista,
            'cada cuadricula pinta los equipos de SU grupo');
    }

    // ── Decorado ────────────────────────────────────────────────────────

    private function vista(string $componente): string
    {
        return file_get_contents(resource_path("js/Components/FormFields/{$componente}.vue"));
    }

    private function css(): string
    {
        return file_get_contents(resource_path('css/app.css'));
    }

    /** El cuerpo de una regla CSS, para poder mirar lo que declara. */
    /**
     * El raton no puede pisar el color del estado.
     *
     * Esto se rompio y se veia de verdad: al pasar por encima, una pastilla
     * verde se quedaba BLANCA Y VACIA. No perdia solo el fondo — el texto de una
     * pastilla marcada es blanco, asi que al blanquear el fondo el rotulo
     * desaparecia con el, y un formato confirmado parecia tener casillas sin
     * contestar.
     *
     * La causa era de especificidad: `.ff-cycle.is-ro:hover` son tres selectores
     * y `.ff-cycle.is-ok` dos, asi que el fondo del hover ganaba al del estado.
     * Un `:hover` que declare `background` sin excluir los estados vuelve a
     * meter el mismo fallo, y por eso lo que se vigila es la FORMA de la regla.
     */
    public function test_el_raton_no_blanquea_una_pastilla_marcada(): void
    {
        $css = $this->css();

        // Ningun hover generico: el que habia pisaba los tres estados.
        $this->assertDoesNotMatchRegularExpression(
            '/\.ff-cycle:hover\s*\{/',
            $css,
            'un hover sobre `.ff-cycle` a secas pisa el fondo de las marcadas',
        );

        // Ni el de solo lectura, que era el que de verdad lo rompia: ahi ademas
        // no tiene sentido — un realce que reacciona al raton dice «esto se
        // toca», y en un documento confirmado no se toca nada.
        $this->assertDoesNotMatchRegularExpression(
            '/\.ff-cycle\.is-ro:hover\s*\{/',
            $css,
            'en solo lectura no hay hover: no hay nada que tocar',
        );

        // El fondo del hover solo donde no hay color que estropear.
        $this->assertMatchesRegularExpression(
            '/\.ff-cycle\.is-off:not\(\.is-ro\):hover\s*\{[^}]*background/',
            $css,
            'la pastilla sin marcar si puede cambiar de fondo al pasar por encima',
        );

        // Y las marcadas se realzan sin tocar el fondo.
        foreach (['is-ok', 'is-bad', 'is-na'] as $estado) {
            $this->assertStringContainsString(
                ".ff-cycle.{$estado}:not(.is-ro):hover",
                $css,
                "el realce de una pastilla «{$estado}» no puede pasar por el fondo",
            );
        }
    }

    private function regla(string $selector): string
    {
        preg_match('/' . preg_quote($selector, '/') . '\s*\{(.*?)\}/s', $this->css(), $m);

        $this->assertNotEmpty($m, "no existe la regla {$selector} en app.css");

        return $m[1];
    }

    /** Sin comentarios de bloque, de linea ni de plantilla. */
    private function sinComentarios(string $vista): string
    {
        return preg_replace(
            ['/<!--.*?-->/s', '/\/\*.*?\*\//s', '/^\s*\/\/.*$/m'],
            '',
            $vista,
        );
    }
}
