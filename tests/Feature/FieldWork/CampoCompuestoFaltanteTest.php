<?php

namespace Tests\Feature\FieldWork;

use Tests\TestCase;

/**
 * Lo obligatorio que falta se dice en rojo DENTRO del campo compuesto.
 *
 * Lo que estaba mal: al confirmar un formato a medias, el servidor devolvia la
 * lista de faltantes y la pantalla la contaba en un aviso arriba del todo. Los
 * campos compuestos —matriz de riesgo, EPP, IHM y banco de preguntas— seguian
 * pintados como si no pasara nada: ni rojo, ni cual fila, ni cuanto. «No
 * respeta los campos obligatorios, se deberia haber puesto en rojo», dijo el
 * dueño del producto, y tenia razon.
 *
 * El contrato con FormFill.vue es una prop: `faltante`, que vale true cuando
 * un intento de guardar dejo ese campo pendiente. Con ella encendida, cada
 * componente señala LO CONCRETO —el trabajador sin completar, la pregunta sin
 * responder— con palabra ademas del color (docs/UI.md §5), no un rojo generico
 * sobre el bloque.
 *
 * Y la matriz ademas se adelanta: la regla del peligro entero
 * (`FormSubmissionService::exigirPeligroEntero()`) se evalua fila a fila al
 * pintar, para que la fila a medias se vea en rojo con sus columnas faltantes
 * ANTES de que el servidor rechace el guardado.
 */
class CampoCompuestoFaltanteTest extends TestCase
{
    /** Los cuatro compuestos y la clave del aviso que le toca a cada uno. */
    protected const COMPONENTES = [
        'RiskMatrixField'      => 'field_work.progress.required_hazards',
        'PersonChecklistField' => 'field_work.progress.required_people',
        'ToolChecklistField'   => 'field_work.progress.required_tools',
        'QuestionBankField'    => 'field_work.progress.required_questions',
    ];

    protected function componente(string $nombre): string
    {
        return file_get_contents(
            resource_path("js/Components/FormFields/{$nombre}.vue")
        );
    }

    /**
     * La prop existe en los cuatro. Si un componente la pierde, FormFill se la
     * pasa igual y Vue la traga sin avisar: el campo pendiente simplemente
     * dejaria de marcarse, y nadie lo veria hasta obra.
     */
    public function test_los_cuatro_compuestos_aceptan_la_prop_faltante(): void
    {
        foreach (array_keys(self::COMPONENTES) as $nombre) {
            $this->assertMatchesRegularExpression(
                '/faltante:\s*\{\s*type:\s*Boolean,\s*default:\s*false\s*\}/',
                $this->componente($nombre),
                "{$nombre} perdio la prop `faltante` del contrato con FormFill"
            );
        }
    }

    /**
     * El rojo lleva su palabra y su cuenta, en el idioma de quien mira.
     *
     * Cada componente pinta el titular `.ff-missing` con la clave de SU aviso
     * —trabajadores, herramientas, peligros o preguntas—, que es lo que
     * distingue «señalar lo que falta» de teñir el bloque entero.
     */
    public function test_cada_compuesto_dice_con_palabra_lo_que_le_falta(): void
    {
        foreach (self::COMPONENTES as $nombre => $clave) {
            $fuente = $this->componente($nombre);

            $this->assertStringContainsString('ff-missing', $fuente,
                "{$nombre} no pinta el aviso rojo de campo pendiente");
            $this->assertStringContainsString($clave, $fuente,
                "{$nombre} pinta el rojo sin su palabra: falta la clave {$clave}");
        }
    }

    /**
     * La matriz espeja la regla del servidor, columna a columna.
     *
     * `exigirPeligroEntero()` exige peligro, riesgo, control, severidad y
     * probabilidad en cuanto la fila se empieza a puntuar. Si el espejo del
     * componente pierde una clave, esa columna podria quedar vacia sin aviso
     * previo y el usuario volveria a enterarse recien al guardar.
     */
    public function test_la_matriz_espeja_las_cinco_columnas_del_peligro_entero(): void
    {
        $fuente = $this->componente('RiskMatrixField');

        preg_match('/CLAVES_PELIGRO\s*=\s*\[([^\]]+)\]/', $fuente, $m);

        $this->assertNotEmpty($m[1] ?? '', 'no se encuentra CLAVES_PELIGRO en RiskMatrixField.vue');

        $claves = array_map(fn ($c) => trim($c, " '\""), array_filter(explode(',', $m[1]), 'trim'));
        sort($claves);

        $this->assertSame(
            ['control', 'peligro', 'probabilidad', 'riesgo', 'severidad'],
            $claves,
            'el espejo de exigirPeligroEntero() perdio una columna: servidor y pantalla dirian cosas distintas'
        );
    }

    /**
     * La fila a medias se anuncia antes de guardar: pastilla «Incompleto» en
     * la cabecera y la nota con las columnas faltantes dentro de la tarjeta.
     */
    public function test_la_fila_a_medias_se_ve_antes_de_guardar(): void
    {
        preg_match('/<template>(.*)<\/template>/s', $this->componente('RiskMatrixField'), $m);
        $plantilla = $m[1] ?? '';

        $this->assertStringContainsString('field_work.risk_matrix.incomplete', $plantilla,
            'la cabecera de la fila a medias perdio su palabra («Incompleto»)');
        $this->assertStringContainsString('field_work.risk_matrix.row_missing', $plantilla,
            'la tarjeta ya no nombra las columnas que faltan antes de guardar');
    }

    /**
     * Las claves nuevas existen en los dos idiomas, con la misma forma.
     * (docs/UI.md §7: las mismas claves en `es` y en `en`, sin excepcion.)
     */
    public function test_las_claves_del_marcado_existen_en_es_y_en_en(): void
    {
        $es = require resource_path('lang/es/field_work.php');
        $en = require resource_path('lang/en/field_work.php');

        $claves = [
            ['progress', 'required_hazards'], ['progress', 'required_people'],
            ['progress', 'required_tools'], ['progress', 'required_questions'],
            ['progress', 'questions_done'],
            ['risk_matrix', 'row_missing'], ['risk_matrix', 'incomplete'],
        ];

        foreach ($claves as [$bloque, $clave]) {
            $this->assertArrayHasKey($clave, $es[$bloque] ?? [], "falta es.field_work.{$bloque}.{$clave}");
            $this->assertArrayHasKey($clave, $en[$bloque] ?? [], "falta en.field_work.{$bloque}.{$clave}");
        }
    }
}
