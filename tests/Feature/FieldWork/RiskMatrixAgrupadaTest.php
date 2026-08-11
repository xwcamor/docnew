<?php

namespace Tests\Feature\FieldWork;

use App\Services\Migration\LegacyFormMapper;
use Tests\TestCase;

/**
 * El AST se lee por actividad, y dentro de cada una sus peligros.
 *
 * Lo que estaba mal: `RiskMatrixField.vue` pintaba una lista PLANA de tarjetas
 * tituladas «Actividad → Peligro». La actividad se repetia en cada una —seis
 * tarjetas diciendo «Bobinado de baja tension fase V» seis veces— y la
 * agrupacion, que es la estructura del documento, no existia. Ademas la
 * probabilidad iba despues de la severidad, al reves que la tabla de la v1.
 *
 * Se colo porque el dato SI es plano: cada peligro es una respuesta con su
 * `row_index`, y se pinto la forma de guardado en vez de la forma del
 * documento. En la v1 son dos tablas —`f1_document_activities` tiene muchos
 * `f1_document_dangers`— y dos vistas: un `card` por actividad
 * (`_f1_document_activity_fields.html.erb`) con la tabla de sus peligros dentro
 * (`_f1_document_danger_fields.html.erb`).
 *
 * Aqui se comprueba lo que se puede comprobar sin navegador: el orden de las
 * columnas, que la actividad se escriba una sola vez por bloque, y —lo mas
 * importante— que la agrupacion sea SOLO DE PRESENTACION y no haya tocado la
 * forma del valor guardado, que es la de los 3 657 AST migrados.
 */
class RiskMatrixAgrupadaTest extends TestCase
{
    protected string $componente;

    protected string $plantilla;

    protected function setUp(): void
    {
        parent::setUp();

        $this->componente = file_get_contents(
            resource_path('js/Components/FormFields/RiskMatrixField.vue')
        );

        preg_match('/<template>(.*)<\/template>/s', $this->componente, $m);
        $this->plantilla = $m[1] ?? '';
    }

    /**
     * El orden de las columnas es el de la tabla de la v1.
     *
     * `_f1_document_activity_fields.html.erb`, cabecera de la tabla de peligros:
     * peligro, riesgo, control, **probabilidad, severidad**, nivel. Aqui la
     * probabilidad iba detras de la severidad, y un AST se rellena leyendo la
     * fila de izquierda a derecha: cambiar el orden es cambiar el gesto de quien
     * lo llena todos los dias.
     */
    public function test_las_columnas_van_en_el_orden_de_la_v1(): void
    {
        $orden = ['danger', 'risk', 'control', 'probability', 'severity', 'level'];

        $posiciones = [];

        foreach ($orden as $columna) {
            $posicion = mb_strpos($this->plantilla, "field_work.risk_matrix.{$columna}'");

            $this->assertNotFalse($posicion, "falta la columna «{$columna}» en la matriz");

            $posiciones[$columna] = $posicion;
        }

        $ordenado = $posiciones;
        asort($ordenado);

        $this->assertSame(array_keys($posiciones), array_keys($ordenado),
            'El orden de las columnas de la matriz no es el de la v1: '
            . implode(' → ', $orden) . '. Sale ' . implode(' → ', array_keys($ordenado)) . '.');
    }

    /**
     * La actividad se escribe UNA vez, en la cabecera de su bloque.
     *
     * Es la diferencia entre agrupar y no agrupar: si el selector de actividad
     * sigue dentro de la tarjeta del peligro, la agrupacion es un adorno y el
     * nombre se repite tantas veces como peligros tenga.
     */
    public function test_la_actividad_titula_el_bloque_y_no_se_repite_en_cada_peligro(): void
    {
        $this->assertSame(1, mb_substr_count($this->plantilla, "field_work.risk_matrix.activity'"),
            'la etiqueta «Actividad» aparece mas de una vez: vuelve a estar en la tarjeta del peligro');

        // Y esta donde tiene que estar: en la cabecera del grupo, antes de que
        // empiecen sus tarjetas.
        $cabecera = mb_strpos($this->plantilla, 'ff-group__head');
        $cuerpo   = mb_strpos($this->plantilla, 'ff-group__body');
        $etiqueta = mb_strpos($this->plantilla, "field_work.risk_matrix.activity'");

        $this->assertNotFalse($cabecera, 'no hay bloque de actividad: la matriz sigue siendo una lista plana');
        $this->assertGreaterThan($cabecera, $etiqueta);
        $this->assertLessThan($cuerpo, $etiqueta,
            'el selector de la actividad tiene que ir en la cabecera del bloque, no entre los peligros');
    }

    /**
     * Las seis columnas llevan el asterisco de obligatorio, como en la v1.
     *
     * En `_f1_document_danger_fields.html.erb` las cinco casillas van con
     * `required: true` y las seis cabeceras con su `(*)`, incluida la de la
     * actividad. El servidor exige las cinco del peligro en
     * `FormSubmissionService::exigirPeligroEntero()` en cuanto la fila se
     * empieza a puntuar.
     */
    public function test_las_columnas_obligatorias_se_marcan(): void
    {
        $this->assertSame(7, mb_substr_count($this->plantilla, 'ff-block__req'),
            'faltan (o sobran) asteriscos: son la actividad y las seis columnas del peligro');
    }

    /**
     * El estado va con PALABRA, nunca solo con color (docs/UI.md §5).
     *
     * La pastilla del nivel es lo unico que se mira de un AST de un vistazo, y
     * al sol —o si no distingues el rojo del verde— el color solo no dice nada.
     */
    public function test_el_nivel_se_dice_con_palabra_y_no_solo_con_color(): void
    {
        foreach (['ff-risk', 'is-none'] as $clase) {
            $this->assertStringContainsString($clase, $this->plantilla);
        }

        // Cada pastilla de nivel lleva su texto traducido al lado de la clase.
        $this->assertSame(
            mb_substr_count($this->plantilla, 'class="ff-risk"'),
            mb_substr_count($this->plantilla, 'field_work.risk_matrix.level_${'),
            'hay una pastilla de nivel sin su palabra'
        );
    }

    /**
     * La forma del valor guardado NO cambia: la agrupacion es de presentacion.
     *
     * Cada peligro sigue siendo UNA respuesta (`form_answers` con su
     * `row_index`) con estas ocho claves, y la actividad viaja repetida en cada
     * fila. Hay 3 657 AST migrados asi; cambiar la forma los rompe a todos y
     * rompe el PDF.
     */
    public function test_la_fila_guardada_sigue_teniendo_las_mismas_claves(): void
    {
        preg_match('/function filaVacia\([^)]*\)\s*\{\s*return\s*\{(.*?)\};/s', $this->componente, $m);

        $this->assertNotEmpty($m[1] ?? '', 'no se encuentra filaVacia() en RiskMatrixField.vue');

        // Una clave por trozo entre comas, tanto `peligro: null` como el
        // abreviado `actividad`.
        $claves = [];

        foreach (explode(',', $m[1]) as $trozo) {
            if (preg_match('/^\s*([a-z_]+)/', $trozo, $clave)) {
                $claves[] = $clave[1];
            }
        }

        sort($claves);

        $this->assertSame(
            ['actividad', 'control', 'nivel', 'peligro', 'probabilidad', 'riesgo', 'severidad', 'valor_riesgo'],
            $claves,
            'la fila de la matriz cambio de forma: los 3 657 AST migrados y el PDF esperan estas ocho claves'
        );
    }

    /**
     * Agrupar no reordena: los peligros de una actividad ya vienen seguidos.
     *
     * La pantalla agrupa por TRAMOS SEGUIDOS de la misma actividad, no por el
     * texto suelto, para no reordenar el documento y para que dos actividades
     * que se llamen igual sigan siendo dos. Eso solo vale si lo que trae la
     * migracion viene seguido, que es lo que se fija aqui: si un dia el
     * migrador intercalara filas de distintas actividades, la pantalla partiria
     * la actividad en varios bloques con el mismo nombre.
     */
    public function test_la_migracion_deja_seguidos_los_peligros_de_cada_actividad(): void
    {
        $filas = (new LegacyFormMapper)->matrizDeRiesgo(
            [['id' => 1, 'name' => 'Inspeccion del area'], ['id' => 2, 'name' => 'Bobinado de baja tension']],
            [
                ['f1_document_activity_id' => 2, 'name_danger' => 'Puente grua', 'name_risk' => 'Golpe',
                 'name_control' => 'Delimitar', 'severity_id' => 3, 'probability_id' => 2, 'risk_value' => 18],
                ['f1_document_activity_id' => 1, 'name_danger' => 'Orden y limpieza', 'name_risk' => 'Caida',
                 'name_control' => 'Senalizar', 'severity_id' => 3, 'probability_id' => 2, 'risk_value' => 20],
                ['f1_document_activity_id' => 2, 'name_danger' => 'Escalera de plataforma', 'name_risk' => 'Caida',
                 'name_control' => 'Amarrar', 'severity_id' => 3, 'probability_id' => 2, 'risk_value' => 18],
            ],
            [3 => 'c3'],
            [2 => 'p2'],
            'f1_document_activity_id',
            [['hasta' => 8, 'clave' => 'alto'], ['hasta' => 15, 'clave' => 'medio'], ['hasta' => 25, 'clave' => 'bajo']],
        );

        $porTramos = [];

        foreach ($filas as $fila) {
            if (end($porTramos) !== $fila['actividad']) {
                $porTramos[] = $fila['actividad'];
            }
        }

        $this->assertSame(['Inspeccion del area', 'Bobinado de baja tension'], $porTramos,
            'cada actividad tiene que ocupar un tramo seguido de filas, y una sola vez');
    }
}
