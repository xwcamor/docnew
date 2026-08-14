<?php

namespace Tests\Feature\FieldWork;

use App\Services\FieldWork\FormFindingsService;
use App\Services\FieldWork\Pdf\Simbolos;
use App\Support\BandasDeRiesgo;
use App\Support\Catalogo;
use App\Support\TextoTraducible;
use Tests\TestCase;

/**
 * Un formato de OTRA empresa, en OTRO idioma, tiene que funcionar sin tocar codigo.
 *
 * DE DONDE SALE ESTA PRUEBA
 * -------------------------
 * El dueño del producto pregunto exactamente esto: «ese puntaje de catastrofico,
 * ¿que pasa si otra empresa tiene otros valores?», «piensa en futuros idiomas y
 * futuros formatos», «no debe existir ningun cabo suelto». Al ir a mirar habia
 * tres, y los tres se rompian EN SILENCIO y en la peor direccion —dando por
 * bueno lo que estaba mal— que es como no se puede fallar en un documento de
 * seguridad.
 *
 * LOS TRES, Y LA FORMA QUE LOS CIERRA
 * -----------------------------------
 *  1. **El tono se adivinaba del castellano.** `tono()` clasificaba con «empieza
 *     por no», asi que «Rechazado» salia CONFORME y «Normal» salia como no
 *     conformidad. Ahora el catalogo lo declara.
 *  2. **Las bandas de riesgo eran configurables siempre que se llamaran alto,
 *     medio y bajo**, porque la clave se usaba de clave de traduccion y de clase
 *     CSS. Y se repartian con un `hasta` acumulado, que da por supuesto que el 1
 *     es lo peor: en la matriz clasica de severidad × probabilidad, donde lo peor
 *     es el 25, las tres bandas salian del reves. Ahora son rangos con rotulo y
 *     tono propios.
 *  3. **El texto del cliente no tenia como traducirse.** Se duplicaba la clave
 *     (`severity_labels` y `severity_labels_en`), y el tercer idioma pedia tocar
 *     esquema, migrador, editor y pantallas. Ahora cualquier texto admite el mapa
 *     por idioma.
 *
 * NADA DE ESTO ROMPE LO QUE HAY. Cada caso de aqui tiene su pareja en la forma
 * vieja, y las dos tienen que seguir dando lo mismo: hay 14 000 entregas y 3 657
 * matrices migradas escritas con cadenas sueltas.
 */
class EscalabilidadDelMotorTest extends TestCase
{
    // ── 1. El tono lo declara el catalogo ───────────────────────────────────

    /**
     * EL FALLO, ESCRITO PARA QUE NO VUELVA.
     *
     * Esta prueba afirma que la heuristica se EQUIVOCA. No es una rareza de
     * laboratorio: es lo que pasaba con el catalogo de cualquier empresa que no
     * hablara como los cuatro formatos de la v1, y de ahi cuelgan el contador de
     * observaciones que firma el supervisor, los simbolos del PDF, el color de
     * la pastilla y los campos de medida de correccion.
     *
     * Si algun dia alguien «arregla» la heuristica para que acierte estos casos,
     * esta prueba salta y hay que leerla: el arreglo no es una lista mas larga de
     * palabras en castellano —«malo», «deficiente», «fail», «rechazado»— porque
     * la siguiente empresa traera otras. El arreglo es declararlo.
     */
    public function test_la_heuristica_del_castellano_se_equivoca_y_por_eso_no_decide(): void
    {
        $reglas = app(FormFindingsService::class);

        // Da por BUENO lo que esta mal, que es la direccion peligrosa.
        foreach (['Rechazado', 'Malo', 'Deficiente', 'Fail'] as $mala) {
            $this->assertSame('ok', $reglas->tonoDeducido($mala),
                "la deduccion da «{$mala}» por conforme: por eso no puede ser ella quien decida");
        }

        // Y cuenta como observacion algo que no lo es.
        $this->assertSame(FormFindingsService::MALA, $reglas->tonoDeducido('Normal'),
            'la deduccion cuenta «Normal» como no conformidad, porque empieza por «no»');
    }

    /** Con el tono declarado, acierta. Es el camino de cualquier formato nuevo. */
    public function test_el_tono_declarado_manda_sobre_la_deduccion(): void
    {
        $config = ['answers' => [
            ['value' => 'Aprobado',  'tone' => 'ok'],
            ['value' => 'Rechazado', 'tone' => 'bad'],
            ['value' => 'Normal',    'tone' => 'ok'],
        ]];

        $reglas = app(FormFindingsService::class);

        $this->assertSame('ok', $reglas->tono('Aprobado', $config));
        $this->assertSame(FormFindingsService::MALA, $reglas->tono('Rechazado', $config));
        $this->assertSame('ok', $reglas->tono('Normal', $config),
            'lo declarado gana: «Normal» ya no es una no conformidad por empezar por «no»');
    }

    /** Y sin declararlo, los cuatro formatos migrados siguen leyendose igual. */
    public function test_la_cadena_suelta_de_lo_migrado_se_sigue_clasificando_como_siempre(): void
    {
        $reglas = app(FormFindingsService::class);
        $config = ['answers' => ['Conforme', 'No conforme', 'No aplica']];

        $this->assertSame('ok', $reglas->tono('Conforme', $config));
        $this->assertSame(FormFindingsService::MALA, $reglas->tono('No conforme', $config));
        $this->assertSame(FormFindingsService::NO_APLICA, $reglas->tono('No aplica', $config));
    }

    /**
     * La leyenda del PDF pone el simbolo que toca, no el que sugiere el idioma.
     *
     * Es donde mas caro sale equivocarse: el papel se firma y se archiva. Con la
     * heuristica sola, un formato «Aprobado / Rechazado» imprimia el ✔ al lado
     * de «Rechazado».
     */
    public function test_la_leyenda_del_papel_usa_el_tono_declarado(): void
    {
        $leyenda = collect(Simbolos::leyenda([
            ['value' => 'Aprobado',  'tone' => 'ok'],
            ['value' => 'Rechazado', 'tone' => 'bad'],
        ]))->keyBy('tono');

        $this->assertSame('Aprobado', $leyenda['ok']['texto']);
        $this->assertSame(Simbolos::OK, $leyenda['ok']['simbolo']);

        $this->assertSame('Rechazado', $leyenda[FormFindingsService::MALA]['texto']);
        $this->assertSame(Simbolos::MALA, $leyenda[FormFindingsService::MALA]['simbolo']);
    }

    /** Un tono inventado no se cuela: se cae a la deduccion, no pinta nada raro. */
    public function test_un_tono_que_no_existe_no_se_acepta(): void
    {
        $this->assertNull(
            Catalogo::tonoDeclarado([['value' => 'X', 'tone' => 'morado']], 'X'),
            'un tono fuera de la lista no tendria ni simbolo ni color: se ignora',
        );
    }

    // ── 2. La matriz es de la empresa ───────────────────────────────────────

    /** La matriz de la v1: lo peor es el 1, y las bandas se declaran con `hasta`. */
    public function test_la_matriz_de_la_v1_se_sigue_repartiendo_igual(): void
    {
        $bandas = BandasDeRiesgo::de(['levels' => [
            ['clave' => 'alto',  'hasta' => 8],
            ['clave' => 'medio', 'hasta' => 15],
            ['clave' => 'bajo',  'hasta' => 25],
        ]]);

        $this->assertSame('alto',  BandasDeRiesgo::deValor(1, $bandas)['clave']);
        $this->assertSame('alto',  BandasDeRiesgo::deValor(8, $bandas)['clave']);
        $this->assertSame('medio', BandasDeRiesgo::deValor(9, $bandas)['clave']);
        $this->assertSame('bajo',  BandasDeRiesgo::deValor(16, $bandas)['clave']);
        $this->assertSame('bajo',  BandasDeRiesgo::tolerable($bandas));
    }

    /**
     * LA MATRIZ AL REVES, que es la clasica de severidad × probabilidad.
     *
     * Aqui lo peor es el 25. Con el reparto viejo —«el primer `hasta` que supere
     * el valor»— un riesgo de 25 caia en la PRIMERA banda declarada, o sea en la
     * critica solo por casualidad de como estuvieran ordenadas; y un 1, lo mas
     * inofensivo posible, tambien. Con rangos, la direccion de la escala deja de
     * importar.
     */
    public function test_una_matriz_donde_lo_peor_es_el_25_se_reparte_bien(): void
    {
        $bandas = BandasDeRiesgo::de(['levels' => [
            ['clave' => 'critico',   'min' => 20, 'max' => 25],
            ['clave' => 'moderado',  'min' => 10, 'max' => 19],
            ['clave' => 'aceptable', 'min' => 1,  'max' => 9, 'tolerable' => true],
        ]]);

        $this->assertSame('critico',   BandasDeRiesgo::deValor(25, $bandas)['clave']);
        $this->assertSame('moderado',  BandasDeRiesgo::deValor(12, $bandas)['clave']);
        $this->assertSame('aceptable', BandasDeRiesgo::deValor(1, $bandas)['clave']);
        $this->assertSame('aceptable', BandasDeRiesgo::tolerable($bandas));
    }

    /**
     * Un valor fuera de todas las bandas sale SIN EVALUAR, no en la ultima.
     *
     * Antes caia en la ultima, y con la matriz de la v1 no se notaba porque sus
     * tres bandas cubren el 1 al 25 enteros. Con rangos escritos a mano puede
     * quedar un hueco, y meter ahi la banda tolerable seria declarar tolerable un
     * peligro sin que nadie lo haya dicho.
     */
    public function test_un_valor_fuera_de_las_bandas_no_se_da_por_tolerable(): void
    {
        $bandas = BandasDeRiesgo::de(['levels' => [
            ['clave' => 'alto', 'min' => 1, 'max' => 5],
            ['clave' => 'bajo', 'min' => 10, 'max' => 25],
        ]]);

        $this->assertNull(BandasDeRiesgo::deValor(7, $bandas), 'el hueco entre bandas es «sin evaluar»');
    }

    /** El rotulo y el color de una banda salen de la plantilla, no de su nombre. */
    public function test_la_banda_trae_su_rotulo_y_su_color(): void
    {
        $bandas = BandasDeRiesgo::de(['levels' => [
            ['clave' => 'critico', 'min' => 20, 'max' => 25,
             'label' => ['es' => 'Riesgo crítico', 'en' => 'Critical risk'], 'tone' => 'bad'],
        ]], 'en');

        $this->assertSame('Critical risk', $bandas[0]['label']);
        $this->assertSame('bad', $bandas[0]['tone']);
    }

    /**
     * Sin declararlos, el reparto por posicion da lo de siempre.
     *
     * Es lo que hace que las cuatro plantillas migradas no haya que reescribirlas:
     * van de peor a mejor, asi que la primera sale roja, la ultima verde y las de
     * en medio ambar — que es exactamente alto/medio/bajo.
     */
    public function test_sin_tono_declarado_el_reparto_por_posicion_da_lo_de_siempre(): void
    {
        $bandas = BandasDeRiesgo::de(['levels' => [
            ['clave' => 'alto', 'hasta' => 8],
            ['clave' => 'medio', 'hasta' => 15],
            ['clave' => 'bajo', 'hasta' => 25],
        ]]);

        $this->assertSame(['bad', 'warn', 'ok'], array_column($bandas, 'tone'));
    }

    /**
     * Sin bandas declaradas NO se inventa ninguna, y un peligro no se cuenta.
     *
     * Antes se caia a la palabra `bajo` escrita en el codigo —el nombre que le
     * puso la v1— y entonces cualquier banda distinta de esa contaba como
     * observacion: en un formato nuevo sin niveles, TODOS los peligros evaluados
     * salian como no conformidades.
     */
    public function test_sin_bandas_declaradas_no_se_inventa_la_tolerable(): void
    {
        $this->assertSame([], BandasDeRiesgo::de([]));
        $this->assertNull(BandasDeRiesgo::tolerable([]));
    }

    // ── 3. Idiomas futuros ──────────────────────────────────────────────────

    /**
     * Un idioma nuevo es una clave mas, no una columna ni una migracion.
     *
     * Es toda la diferencia con lo que habia: `label_es`/`label_en` y
     * `severity_labels`/`severity_labels_en` funcionan con dos idiomas y con el
     * tercero hay que tocar esquema, migrador, editor y pantallas.
     */
    public function test_un_idioma_nuevo_no_pide_ni_columna_ni_migracion(): void
    {
        $texto = ['es' => 'Casco de seguridad', 'en' => 'Hard hat', 'pt' => 'Capacete'];

        $this->assertSame('Capacete', TextoTraducible::de($texto, 'pt'));
        $this->assertSame('Hard hat', TextoTraducible::de($texto, 'en'));
    }

    /**
     * Lo que nadie tradujo NO sale en blanco.
     *
     * Un hueco donde va el nombre de un equipo de proteccion es inaceptable en un
     * documento que se lee en obra; un rotulo en otro idioma dice muchisimo mas.
     */
    public function test_lo_que_falta_por_traducir_cae_a_lo_que_haya(): void
    {
        $this->assertSame('Casco', TextoTraducible::de(['es' => 'Casco'], 'pt'));
        $this->assertSame('', TextoTraducible::de(null, 'es'));
    }

    /** Y la cadena suelta de siempre sigue valiendo, que es lo que hay escrito. */
    public function test_la_cadena_suelta_sigue_siendo_una_forma_valida(): void
    {
        $this->assertSame('Casco de seguridad', TextoTraducible::de('Casco de seguridad', 'en'));
    }

    /**
     * EL VALOR NO SE TRADUCE NUNCA. Solo el rotulo.
     *
     * Es la linea que no se puede cruzar: el valor es la clave que se guarda en
     * `form_answers` y la que casa con las 14 000 entregas migradas. Si se
     * tradujera, el mismo formato llenado en una tablet en ingles y en otra en
     * castellano guardaria dos documentos distintos, y ninguno de los dos
     * cuadraria con el historico.
     */
    public function test_lo_que_se_guarda_es_el_valor_y_lo_que_se_lee_es_el_rotulo(): void
    {
        $answers = [
            ['value' => 'Conforme',    'label' => ['en' => 'Compliant'],     'tone' => 'ok'],
            ['value' => 'No conforme', 'label' => ['en' => 'Non-compliant'], 'tone' => 'bad'],
        ];

        $this->assertSame(['Conforme', 'No conforme'], Catalogo::valores($answers),
            'los valores no cambian con el idioma: son la clave de lo guardado');

        $this->assertSame('Non-compliant', Catalogo::etiqueta($answers, 'No conforme', 'en'));

        // Y en un idioma que el mapa no trae se lee OTRO idioma, no un hueco.
        //
        // Es deliberado y conviene entender el porque, que ademas dice como hay
        // que escribir la config: el valor puede ser perfectamente un codigo
        // («EPP-01»), y enseñar un codigo donde va el nombre de un equipo es peor
        // que enseñarlo en ingles. La recomendacion para quien configura es poner
        // TODOS los idiomas en el mapa, incluido aquel en que escribio el valor.
        $this->assertSame('Non-compliant', Catalogo::etiqueta($answers, 'No conforme', 'pt'));
    }

    /**
     * Una respuesta que el catalogo ya no tiene se sigue leyendo.
     *
     * Pasa cuando el formato cambia de version y hay entregas viejas. Borrar el
     * texto seria reescribir un documento firmado.
     */
    public function test_una_respuesta_fuera_del_catalogo_se_sigue_leyendo(): void
    {
        $this->assertSame('Lo que fuera', Catalogo::etiqueta(['Conforme'], 'Lo que fuera'));
    }

    // ── Las dos mitades tienen que decir lo mismo ───────────────────────────

    /**
     * El gemelo de la pantalla existe y aplica las mismas reglas.
     *
     * No hay navegador en la suite, asi que esto lee el modulo como texto —el
     * mismo recurso que `ChecklistCicloTest`— y comprueba que las decisiones que
     * de verdad importan estan escritas en los dos lados. Si una mitad cambia y
     * la otra no, la pantalla pinta rojo y el contador dice cero, que es el fallo
     * mas caro que puede tener este motor.
     */
    public function test_la_pantalla_lee_los_catalogos_con_las_mismas_reglas(): void
    {
        $js = file_get_contents(resource_path('js/Support/catalogo.js'));

        // El tono declarado manda, y solo si calla se deduce.
        $this->assertStringContainsString('export function tonoDeclarado', $js);
        $this->assertStringContainsString("TONOS = ['ok', 'bad', 'na']", $js,
            'los tonos de una respuesta son los mismos tres que sabe contar el servidor');

        // El valor no se traduce; el rotulo si.
        $this->assertStringContainsString('export function valoresDeCatalogo', $js);
        $this->assertStringContainsString('export function etiquetaDeCatalogo', $js);

        // Las bandas, por rango y con su tono.
        $this->assertStringContainsString('n >= b.min && n <= b.max', $js,
            'la banda se busca por rango, no por «hasta» acumulado');
        $this->assertStringContainsString("TONOS_BANDA = ['bad', 'warn', 'ok', 'info', 'off']", $js);

        // Y el valor de la matriz sale de la tabla, no del producto.
        $this->assertStringContainsString('export function valorDeLaMatriz', $js);
    }

    /**
     * Ni una sola clase de color derivada del NOMBRE de una banda.
     *
     * Es el cabo suelto concreto: `.ff-risk.is-alto` existe en la hoja de estilos
     * y `.ff-risk.is-critico` no, asi que la primera empresa que llamara distinto
     * a sus bandas veia el nivel de riesgo de sus AST en gris. La clase sale
     * ahora del tono, que es una lista cerrada.
     */
    public function test_ningun_color_sale_del_nombre_de_una_banda(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['is-alto', 'is-medio', 'is-bajo'] as $nombre) {
            $this->assertStringNotContainsString(".ff-risk.{$nombre}", $css,
                "«{$nombre}» es el nombre que le puso la v1, no un tono del sistema");
        }

        foreach (['is-bad', 'is-warn', 'is-ok'] as $tono) {
            $this->assertStringContainsString(".ff-risk.{$tono}", $css);
        }
    }
    // ── Y todo esto se configura en pantalla, no por SQL ────────────────────

    /**
     * LA MATRIZ DE OTRA EMPRESA SE CARGA DESDE EL EDITOR.
     *
     * Este era el cabo suelto mas practico de todos: `config.matrix` y
     * `config.levels` se LEIAN desde que existe el motor y no estaban en la
     * lista de claves configurables, asi que la unica forma de cargar la tabla
     * de otra empresa era entrar a la base y editar el JSON a mano. Con los
     * rotulos de los ejes pasaba lo mismo: un formato que no viniera de la
     * migracion enseñaba «c3» y «p2» pelados en la tablet y en el papel.
     */
    public function test_la_matriz_las_bandas_y_los_rotulos_se_configuran_en_pantalla(): void
    {
        $ofrecidas = $this->configurablesDe('risk_matrix');

        foreach (['matrix', 'levels', 'severity_labels', 'probability_labels'] as $clave) {
            $this->assertContains($clave, $ofrecidas,
                "«{$clave}» solo se podia cargar por SQL: tiene que ofrecerla el editor");
        }
    }

    /**
     * Y cada una con SU control, no con el de lista.
     *
     * Con el control de textos, una banda se guardaria como «[object Object]» y
     * el AST se quedaria sin niveles al primer guardado desde la pantalla, sin
     * avisar. Es el mismo fallo que ya documentaron los grupos del EPP.
     */
    public function test_cada_configuracion_tiene_el_control_que_le_toca(): void
    {
        $controlador = new \App\Http\Controllers\BusinessManagement\FormTemplateController();

        $metodo = new \ReflectionMethod($controlador, 'controlDeClave');
        $metodo->setAccessible(true);

        $esperado = [
            'answers'            => 'answers',
            'matrix'             => 'matrix',
            'levels'             => 'levels',
            'severity_labels'    => 'labels',
            'probability_labels' => 'labels',
            // Y lo que ya estaba sigue igual.
            'groups'             => 'groups',
            'items'              => 'list',
        ];

        foreach ($esperado as $clave => $control) {
            $this->assertSame($control, $metodo->invoke($controlador, $clave),
                "«{$clave}» necesita el control «{$control}»");
        }

        $editor = file_get_contents(resource_path('js/Components/FormTemplates/FieldConfigEditor.vue'));

        foreach (array_unique(array_values($esperado)) as $control) {
            $this->assertStringContainsString("item.control === '{$control}'", $editor,
                "el editor no despacha el control «{$control}»");
        }
    }

    /**
     * Los idiomas del editor son los que el super tiene activos.
     *
     * Es lo que hace que dar de alta un idioma abra su recuadro sin tocar codigo.
     * Escrita a mano la pareja es/en —que es lo que habia— el tercer idioma no
     * tiene donde ir.
     */
    public function test_el_editor_ofrece_los_idiomas_activos_y_no_una_pareja_escrita(): void
    {
        $editor = file_get_contents(resource_path('js/Components/FormTemplates/FieldConfigEditor.vue'));

        $this->assertStringContainsString('availableLocales', $editor);
        $this->assertStringNotContainsString("['es', 'en']", $editor);
    }

    /**
     * Guardar sin tocar nada NO reescribe la config de los cuatro formatos.
     *
     * Cada cambio de un formato publicado es una version nueva del documento, y
     * convertir las tres respuestas de un EPP a la forma larga solo por haber
     * abierto el editor seria ensuciar el historial de todos los clientes de
     * golpe. Por eso los controles guardan la forma minima.
     */
    public function test_los_controles_guardan_la_forma_minima(): void
    {
        $respuestas = file_get_contents(resource_path('js/Components/FormTemplates/AnswerListEditor.vue'));

        $this->assertStringContainsString(
            'if (! tone && ! Object.keys(traducciones).length) return value;',
            $respuestas,
            'una respuesta sin tono ni traducciones se guarda como la cadena de siempre',
        );
    }

    /** @return array<int, string> */
    private function configurablesDe(string $tipo): array
    {
        $controlador = new \App\Http\Controllers\BusinessManagement\FormTemplateController();

        $metodo = new \ReflectionMethod($controlador, 'catalogoDeTipos');
        $metodo->setAccessible(true);

        $catalogo = collect($metodo->invoke($controlador))->firstWhere('value', $tipo);

        return array_column($catalogo['config'] ?? [], 'key');
    }
}
