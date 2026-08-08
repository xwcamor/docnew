<?php

namespace Tests\Feature\Migration;

use App\Services\Migration\LegacyFormMapper;
use Tests\TestCase;

/**
 * La traduccion de los formatos de la v1, sin base de datos de por medio.
 *
 * Lo que se prueba aqui no es que la migracion corra, sino que signifique lo
 * mismo. Las respuestas de la v1 eran numeros y cada formato les daba un
 * sentido distinto: si alguien cambia este mapeo, miles de "Cumple" pasan a
 * decir "No cumple" en documentos ya firmados y nadie se entera.
 */
class LegacyFormMapperTest extends TestCase
{
    protected LegacyFormMapper $mapa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapa = new LegacyFormMapper;
    }

    public function test_los_numeros_de_la_v1_significan_cosas_distintas_en_cada_formato(): void
    {
        // El mismo 2: en EPP es "no conforme", en IHM es "no cumple".
        $this->assertSame('Si', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_PTF, 1));
        $this->assertSame('No', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_PTF, 0));

        $this->assertSame('Conforme', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_EPP, 1));
        $this->assertSame('No conforme', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_EPP, 2));
        $this->assertSame('No aplica', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_EPP, 0));

        $this->assertSame('Cumple', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_IHM, 1));
        $this->assertSame('No cumple', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_IHM, 2));
        $this->assertSame('No aplica', $this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_IHM, 0));
    }

    public function test_una_respuesta_sin_valor_no_se_convierte_en_no_aplica(): void
    {
        // 2 700 respuestas de EPP venian nulas. Nulo es "no se contesto", que no
        // es lo mismo que "no aplica": rellenarlo seria inventar el documento.
        $this->assertNull($this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_EPP, null));
        $this->assertNull($this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_EPP, ''));
        $this->assertNull($this->mapa->etiqueta(LegacyFormMapper::RESPUESTAS_EPP, 9));
    }

    public function test_la_matriz_de_riesgo_saca_una_fila_por_peligro_con_su_actividad(): void
    {
        $filas = $this->mapa->matrizDeRiesgo(
            [['id' => 1, 'name' => 'Izaje de carga'], ['id' => 2, 'name' => 'Limpieza']],
            [
                ['f1_document_activity_id' => 1, 'name_danger' => 'Carga suspendida',
                 'name_risk' => 'Golpe', 'name_control' => 'Delimitar area',
                 'severity_id' => 3, 'probability_id' => 2, 'risk_value' => 6],
                ['f1_document_activity_id' => 1, 'name_danger' => 'Superficie humeda',
                 'name_risk' => 'Caida', 'name_control' => 'Senalizar',
                 'severity_id' => 1, 'probability_id' => 1, 'risk_value' => 1],
            ],
            [1 => 'c1', 3 => 'c3'],
            [1 => 'p1', 2 => 'p2'],
            'f1_document_activity_id',
        );

        $this->assertCount(3, $filas);
        $this->assertSame('Izaje de carga', $filas[0]['actividad']);
        $this->assertSame('c3', $filas[0]['severidad']);
        $this->assertSame('p2', $filas[0]['probabilidad']);
        $this->assertSame(6, $filas[0]['valor_riesgo']);

        // La actividad sin peligros existia como fila en la v1: no se pierde.
        $this->assertSame('Limpieza', $filas[2]['actividad']);
        $this->assertNull($filas[2]['peligro']);

        // El motor exige severidad y probabilidad en toda fila de la matriz,
        // aunque vengan vacias.
        foreach ($filas as $fila) {
            $this->assertArrayHasKey('severidad', $fila);
            $this->assertArrayHasKey('probabilidad', $fila);
        }
    }

    public function test_el_banco_de_preguntas_conserva_el_texto_ademas_del_id(): void
    {
        $lista = $this->mapa->bancoDePreguntas(
            [['ptf_question_id' => 1, 'answer' => 1], ['ptf_question_id' => 2, 'answer' => 0]],
            [1 => 'Tienes el permiso?', 2 => 'Conoces el plan de emergencia?'],
        );

        $this->assertSame('Tienes el permiso?', $lista[0]['question']);
        $this->assertSame('Si', $lista[0]['answer']);
        $this->assertSame('No', $lista[1]['answer']);
    }

    public function test_el_checklist_de_epp_va_por_persona_y_arrastra_la_correccion(): void
    {
        $valor = $this->mapa->checklistDePersona(
            ['plan_worker_id' => 7, 'correction_measure' => 'Cambiar casco',
             'deadline_date' => '2026-01-31', 'correction_verification' => 'Verificado'],
            [['epp_item_id' => 1, 'answer' => 2]],
            [1 => 'Casco'],
            42,
        );

        $this->assertSame(42, $valor['person_id']);
        $this->assertSame(7, $valor['legacy_plan_worker_id']);
        $this->assertSame('Cambiar casco', $valor['correction_measure']);
        $this->assertSame('Casco', $valor['items'][0]['item']);
        $this->assertSame('No conforme', $valor['items'][0]['answer']);
    }

    public function test_el_checklist_de_ihm_va_por_herramienta(): void
    {
        $valor = $this->mapa->checklistDeHerramienta(
            ['name' => 'Amoladora', 'is_enabled' => 0, 'correction_measure' => 'Retirar', 'responsible' => 'Jefe'],
            [['ihm_item_id' => 5, 'answer' => 2]],
            [5 => 'Guardas y dispositivos de seguridad.'],
        );

        $this->assertSame('Amoladora', $valor['tool']);
        $this->assertFalse($valor['habilitada']);
        $this->assertSame('No cumple', $valor['items'][0]['answer']);
    }

    public function test_los_textos_del_ast_que_la_plantilla_no_reproduce_acaban_en_observaciones(): void
    {
        $texto = $this->mapa->observacionesAst('Permiso de altura', 'Arnes, linea de vida');

        $this->assertStringContainsString('Permisos adicionales: Permiso de altura', $texto);
        $this->assertStringContainsString('Herramientas adicionales: Arnes, linea de vida', $texto);

        $this->assertNull($this->mapa->observacionesAst(null, ''));
    }

    /**
     * Las claves que escribe la migracion son las que lee la pantalla.
     *
     * Aqui se escribian en castellano —`respuesta`, `pregunta`, `herramienta`—
     * y los campos compuestos del motor leen `answer`, `question` y `tool`.
     * Resultado: las 14 435 entregas migradas se abrian **en blanco**. El nombre
     * del item si cuadraba, porque esa clave se llama igual en los dos sitios, y
     * ninguna respuesta salia marcada.
     *
     * No salto antes porque el PDF aplana el JSON tal cual, sin buscar claves
     * concretas: el papel salia perfecto y la pantalla vacia. Y no era cosmetico
     * — abrir un EPP migrado y pulsar Guardar sobrescribia con nulos las
     * respuestas reales, porque la pantalla creia que no habia ninguna.
     *
     * Esta prueba compara contra los nombres que leen PersonChecklistField.vue,
     * ToolChecklistField.vue y QuestionBankField.vue. Si alguien cambia un lado,
     * salta aqui y no en obra.
     */
    public function test_las_claves_son_las_que_lee_el_motor(): void
    {
        $epp = $this->mapa->checklistDePersona(
            ['plan_worker_id' => 1], [['epp_item_id' => 1, 'answer' => 2]], [1 => 'Casco'], 7,
        );
        $this->assertSame(LegacyFormMapper::CLAVES_DE_ITEM, array_keys($epp['items'][0]));

        $ihm = $this->mapa->checklistDeHerramienta(
            ['name' => 'Amoladora'], [['ihm_item_id' => 5, 'answer' => 2]], [5 => 'Guardas'],
        );
        $this->assertSame(LegacyFormMapper::CLAVES_DE_ITEM, array_keys($ihm['items'][0]));
        $this->assertArrayHasKey('tool', $ihm, 'ToolChecklistField.vue lee `tool`, no `herramienta`');

        $ptf = $this->mapa->bancoDePreguntas([['ptf_question_id' => 3, 'answer' => 1]], [3 => '¿Permiso?']);
        $this->assertSame(LegacyFormMapper::CLAVES_DE_PREGUNTA, array_keys($ptf[0]));
    }

    /**
     * La matriz separa la consecuencia del numero.
     *
     * En la v1 la fila es actividad → peligro → **riesgo** → control, donde el
     * riesgo es la consecuencia en texto (`name_risk`), y aparte va el valor de
     * la matriz (`risk_value`, del 1 al 25). El componente llamaba `riesgo` al
     * numero, asi que los 3 657 AST migrados salian con la consecuencia donde va
     * el numero y sin banda de color. Manda el nombre del dominio, que es el de
     * la v1.
     */
    public function test_la_matriz_separa_la_consecuencia_del_valor(): void
    {
        $filas = $this->mapa->matrizDeRiesgo(
            [['id' => 1, 'name' => 'Excavacion']],
            [['f1_document_activity_id' => 1, 'name_danger' => 'Caida', 'name_risk' => 'Fracturas',
              'name_control' => 'Señalizar', 'severity_id' => 2, 'probability_id' => 3, 'risk_value' => 12]],
            [2 => 'c2'], [3 => 'p3'], 'f1_document_activity_id',
        );

        $this->assertSame('Fracturas', $filas[0]['riesgo'], 'el riesgo es la consecuencia, en texto');
        $this->assertSame(12, $filas[0]['valor_riesgo'], 'el numero de la matriz va aparte');
    }

    public function test_los_marcadores_de_la_v1_no_son_archivos(): void
    {
        // Esto es el corazon del paso de evidencias: 30 695 referencias de la v1
        // son estas dos cadenas y detras no hay fichero ninguno.
        $this->assertFalse($this->mapa->esArchivoReal('detected_by_IA'));
        $this->assertFalse($this->mapa->esArchivoReal('signed_by_IA'));
        $this->assertFalse($this->mapa->esArchivoReal(''));
        $this->assertFalse($this->mapa->esArchivoReal(null));

        $this->assertTrue($this->mapa->esArchivoReal('5c10226a-a68e-4c37-ac48-9ed838556b27.webp'));
    }
}
