<?php

namespace App\Services\Migration;

/**
 * Traduce los formatos llenados de la v1 a respuestas del motor.
 *
 * En el sistema anterior cada formato era su propia tabla con sus columnas
 * fijas: el AST guardaba actividades y peligros en dos tablas encadenadas, el
 * PTF una fila por pregunta, el EPP una fila por trabajador y item, el IHM una
 * fila por herramienta e item. Aqui todo eso son `form_answers` de un campo
 * compuesto, y esta clase es la unica que sabe como se pasa de una forma a la
 * otra.
 *
 * Se mantiene aparte del comando a proposito: es logica pura, sin base de
 * datos, y se puede probar con datos sinteticos.
 */
class LegacyFormMapper
{
    /**
     * Las claves de una fila las fija el motor, no esta clase.
     *
     * Aqui se escribian en castellano —`respuesta`, `pregunta`, `herramienta`—
     * mientras los campos compuestos del front leen `answer`, `question` y
     * `tool`. El resultado es que las 14 435 entregas migradas se abrian **en
     * blanco**: los nombres de los items cuadraban (esa clave si coincidia) y
     * ninguna respuesta salia marcada.
     *
     * No salto antes porque el PDF aplana el JSON tal cual, sin buscar claves
     * concretas: el papel salia perfecto y la pantalla vacia. Y era peor que
     * cosmetico — abrir un EPP migrado y darle a guardar sobrescribia con nulos
     * cinco años de respuestas.
     *
     * Lo leen PersonChecklistField.vue, ToolChecklistField.vue y
     * QuestionBankField.vue. La prueba `test_las_claves_son_las_que_lee_el_motor`
     * las fija.
     */
    public const CLAVES_DE_ITEM = ['item_id', 'item', 'answer'];
    public const CLAVES_DE_PREGUNTA = ['pregunta_id', 'question', 'answer'];

    /**
     * Las respuestas numericas de la v1 no significan lo mismo en cada formato,
     * y en ningun caso son el indice de la lista de opciones. Salen de leer las
     * vistas originales (show_pdf_page1.erb de cada formato), que es donde
     * estaba escrito el significado:
     *
     *   PTF: `if answer == 1` marca la casilla Si, `if answer == 0` la de No.
     *   EPP: `when 1` conforme, `when 2` no conforme, `when 0` no aplica.
     *   IHM: `when 1` cumple,   `when 2` no cumple,  `when 0` no aplica.
     *
     * Ojo: en IHM el catalogo de la plantilla nueva lista las opciones en el
     * orden 'No cumple, Cumple, No aplica'. Por eso aqui se guarda la etiqueta
     * y nunca el numero: si se guardara el indice, un cambio de orden en el
     * catalogo cambiaria el significado de lo ya firmado.
     */
    public const RESPUESTAS_PTF = [1 => 'Si', 0 => 'No'];
    public const RESPUESTAS_EPP = [1 => 'Conforme', 2 => 'No conforme', 0 => 'No aplica'];
    public const RESPUESTAS_IHM = [1 => 'Cumple', 2 => 'No cumple', 0 => 'No aplica'];

    /** Una respuesta sin valor se queda sin valor: no se inventa un "no aplica". */
    public function etiqueta(array $mapa, mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $mapa[(int) $valor] ?? null;
    }

    /**
     * AST y PTF — matriz de riesgo.
     *
     * Una fila por peligro, con su actividad al lado. Las actividades que se
     * quedaron sin peligros tambien salen: en la v1 existian como fila y
     * perderlas seria cambiar el documento.
     *
     * @param  array  $actividades  [['id' => 1, 'name' => '...'], ...]
     * @param  array  $peligros     [['f1_document_activity_id' => 1, 'name_danger' => ...], ...]
     * @param  array  $severidades  [id => 'c3']
     * @param  array  $probabilidades [id => 'p2']
     * @return array<int, array<string, mixed>> una entrada por fila de la matriz
     */
    public function matrizDeRiesgo(
        array $actividades,
        array $peligros,
        array $severidades,
        array $probabilidades,
        string $claveActividad,
    ): array {
        $porActividad = [];

        foreach ($peligros as $p) {
            $porActividad[(int) $p[$claveActividad]][] = $p;
        }

        $filas = [];

        foreach ($actividades as $a) {
            $suyos = $porActividad[(int) $a['id']] ?? [];

            if ($suyos === []) {
                $filas[] = [
                    'actividad'    => $a['name'],
                    'peligro'      => null,
                    'riesgo'       => null,
                    'control'      => null,
                    'severidad'    => null,
                    'probabilidad' => null,
                    'valor_riesgo' => null,
                ];

                continue;
            }

            foreach ($suyos as $p) {
                $filas[] = [
                    'actividad'    => $a['name'],
                    'peligro'      => $p['name_danger'],
                    'riesgo'       => $p['name_risk'],
                    'control'      => $p['name_control'],
                    'severidad'    => $severidades[(int) $p['severity_id']] ?? null,
                    'probabilidad' => $probabilidades[(int) $p['probability_id']] ?? null,
                    'valor_riesgo' => isset($p['risk_value']) ? (int) $p['risk_value'] : null,
                ];
            }
        }

        return $filas;
    }

    /**
     * PTF — banco de preguntas.
     *
     * Las 17 preguntas de un PTF eran 17 filas en la v1. Aqui son una sola
     * respuesta con la lista dentro: el motor las pinta a partir del catalogo
     * de la plantilla y lo que hay que conservar es que contesto cada una.
     *
     * @param  array  $respuestas  [['ptf_question_id' => 3, 'answer' => 1], ...]
     * @param  array  $preguntas   [id => 'texto de la pregunta']
     */
    public function bancoDePreguntas(array $respuestas, array $preguntas): array
    {
        $lista = [];

        foreach ($respuestas as $r) {
            $id = (int) $r['ptf_question_id'];

            $lista[] = [
                'pregunta_id' => $id,
                'question'    => $preguntas[$id] ?? null,
                'answer'      => $this->etiqueta(self::RESPUESTAS_PTF, $r['answer'] ?? null),
            ];
        }

        return $lista;
    }

    /**
     * EPP — una fila por trabajador del plan con sus items de proteccion.
     *
     * @param  array  $trabajador  fila de f3_document_workers
     * @param  array  $respuestas  [['epp_item_id' => 1, 'answer' => 1], ...] de ese trabajador
     * @param  array  $items       [id => 'Casco']
     */
    public function checklistDePersona(array $trabajador, array $respuestas, array $items, ?int $personaId): array
    {
        $lista = [];

        foreach ($respuestas as $r) {
            $id = (int) $r['epp_item_id'];

            $lista[] = [
                'item_id' => $id,
                'item'    => $items[$id] ?? null,
                'answer'  => $this->etiqueta(self::RESPUESTAS_EPP, $r['answer'] ?? null),
            ];
        }

        return [
            'person_id'               => $personaId,
            'legacy_plan_worker_id'   => (int) $trabajador['plan_worker_id'],
            'correction_measure'      => $trabajador['correction_measure'] ?? null,
            'deadline_date'           => $trabajador['deadline_date'] ?? null,
            'correction_verification' => $trabajador['correction_verification'] ?? null,
            'items'                   => $lista,
        ];
    }

    /**
     * IHM — una fila por herramienta inspeccionada con sus puntos de control.
     *
     * @param  array  $herramienta  fila de f4_document_tools
     * @param  array  $items        [['ihm_item_id' => 1, 'answer' => 1], ...]
     * @param  array  $catalogo     [id => 'Condiciones generales...']
     */
    public function checklistDeHerramienta(array $herramienta, array $items, array $catalogo): array
    {
        $lista = [];

        foreach ($items as $i) {
            $id = (int) $i['ihm_item_id'];

            $lista[] = [
                'item_id' => $id,
                'item'    => $catalogo[$id] ?? null,
                'answer'  => $this->etiqueta(self::RESPUESTAS_IHM, $i['answer'] ?? null),
            ];
        }

        return [
            'tool'               => $herramienta['name'],
            'habilitada'         => isset($herramienta['is_enabled']) ? (bool) $herramienta['is_enabled'] : null,
            'medida_correctiva'  => $herramienta['correction_measure'] ?? null,
            'responsable'        => $herramienta['responsible'] ?? null,
            'items'              => $lista,
        ];
    }

    /**
     * El AST de la v1 tenia dos campos de texto —permisos adicionales y
     * herramientas adicionales— que la plantilla nueva no reproduce como campos
     * propios. En vez de perderlos se escriben, etiquetados, en las
     * observaciones del formato.
     *
     * La columna `observations` de la v1 no se trae: era un entero (0, 1 o 3),
     * no un texto, y no significa nada fuera de aquel formulario.
     */
    public function observacionesAst(?string $permisos, ?string $herramientas): ?string
    {
        $partes = [];

        if (filled($permisos)) {
            $partes[] = 'Permisos adicionales: ' . trim($permisos);
        }

        if (filled($herramientas)) {
            $partes[] = 'Herramientas adicionales: ' . trim($herramientas);
        }

        return $partes === [] ? null : implode("\n", $partes);
    }

    /**
     * Las firmas y fotos de la v1 que no son un archivo.
     *
     * La aplicacion escribia la cadena `detected_by_IA` (foto) o `signed_by_IA`
     * (firma) en la columna del archivo cuando el navegador creia haber
     * reconocido a la persona. No hay fichero detras: de 9 012 fotos de
     * trabajador, 7 508 eran esa cadena. No es evidencia y no se migra como si
     * lo fuera.
     */
    public const MARCADORES_SIN_ARCHIVO = ['detected_by_IA', 'signed_by_IA'];

    /** ¿Esta referencia apunta a un archivo real o es un marcador de la v1? */
    public function esArchivoReal(?string $referencia): bool
    {
        $referencia = trim((string) $referencia);

        return $referencia !== '' && ! in_array($referencia, self::MARCADORES_SIN_ARCHIVO, true);
    }
}
