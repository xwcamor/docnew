<?php

return [
    /*
     * Textos del PDF firmado de una entrega de formato: el documento que la
     * empresa conserva. Es lo unico que se ve fuera del sistema, asi que aqui
     * no hay jerga tecnica ni nombres de columna.
     */
    'pdf' => [
        'title'         => 'Formato de seguridad',
        'plan'          => 'Plan de trabajo',
        'code'          => 'Código',
        'work_order'    => 'Orden de trabajo',
        'company'       => 'Empresa contratista',
        'tax_id'        => 'Documento',
        'work_type'     => 'Tipo de trabajo',
        'location'      => 'Ubicación',
        'date_start'    => 'Inicio',
        'date_end'      => 'Fin',
        'description'   => 'Descripción del trabajo',
        'submitted_at'  => 'Formato entregado',
        'form_version'  => 'Versión',
        // Sin «Estado». Un «Confirmado» impreso dentro del propio documento es
        // información del sistema, y además se queda congelado en el papel el
        // día que se imprimió: lo que da fe es el identificador del pie.

        'content'   => 'Contenido del formato',
        'observations' => 'Observaciones',

        // Adjuntos. En los formatos de solo subida la foto del papel ES el
        // documento, por eso sale a página completa.
        'attachments'   => 'Documento adjunto',
        'fingerprint'   => 'Huella SHA-256',
        'attachment_not_embeddable' => 'El adjunto no es una imagen y no puede incrustarse; queda identificado por su huella.',

        // Firmas registradas.
        'signatures'      => 'Firmas registradas',
        'no_signatures'   => 'Esta entrega no tiene firmas registradas.',
        // Los dos grupos. Quien abre el documento pregunta «quién estuvo» y
        // «quién lo autorizó»: son dos preguntas y son dos tablas.
        'workers'         => 'Trabajadores',
        'approvers'       => 'Aprobadores',
        'approver_role'   => 'Rol',
        'submission_signature' => 'Entrega del formato',
        // La firma trazada, no la cara: es lo que se espera ver al lado de
        // un nombre en un documento firmado, y ademas la cara solo existe
        // cuando el reconocimiento fallo.
        'signature'       => 'Firma',
        'signer'          => 'Firmante',
        'document_number' => 'Documento',
        'signed_at'       => 'Hora',
        'col_method'      => 'Método',
        // El porcentaje y no la distancia: quien lee el informe no tiene por
        // qué saber que «0.15» es bueno y «0.55» no.
        'match'           => 'Coincidencia de la cara :value %',
        'override_reason' => 'Motivo',

        // ── Matriz de riesgo (AST y PTF) ─────────────────────────────────
        // El papel de la v1 imprimia la evaluacion en crudo: `severity_id` (el
        // id numerico) y el ranking 1-25 a secas, sin decir la banda. Aqui va
        // el nombre del catalogo y la banda con su palabra.
        'risk_matrix' => [
            'activity_n'         => 'Actividad :n',
            'unnamed_activity'   => 'Actividad sin nombre',
            'no_hazards'         => 'Esta actividad no registró peligros.',
            'evaluation'         => 'Evaluación',
            'col_danger'         => 'Peligro',
            'col_risk'           => 'Riesgo',
            'col_control'        => 'Control',
            'col_probability'    => 'Probabilidad',
            'col_severity'       => 'Severidad',
            'col_level'          => 'Nivel',
            'total_hazards'      => 'Peligros: :count',
            'hazards_rated'      => ':done de :total evaluados',
            // Solo la banda. Ponía «Peor: Bajo», y en una actividad cuyo peor
            // peligro es «Bajo» la palabra sobra y encima suena a reproche: la
            // píldora ya va del color de la banda y está junto al recuento de
            // peligros, así que se entiende sin adjetivarla.
            'worst'              => ':level',
            'not_assessed'       => 'Sin evaluar',
            'not_assessed_count' => ':count sin evaluar',
            'incomplete_count'   => ':count sin completar',
            'missing'            => 'Falta',
            // El sufijo es la CLAVE de banda de `config.levels`, no una
            // palabra: por eso es española tambien en el fichero en ingles. Con
            // bandas propias el parcial cae a la clave y sigue imprimiendo
            // palabra junto al color.
            'level_alto'         => 'Alto',
            'level_medio'        => 'Medio',
            'level_bajo'         => 'Bajo',
        ],

        // ── Banco de preguntas (PTF «Pare, Tome 5») ──────────────────────
        // En la v1 las columnas se rotulaban «Sí» y «N/A» cuando lo que se
        // guarda es Sí/No, y el documento no decia en ninguna parte cuantos
        // «No» habia: habia que recorrer 17 filas buscando equis.
        'question_bank' => [
            'number'          => 'Nº',
            'question'        => 'Pregunta',
            'answer'          => 'Respuesta',
            'answered'        => ':done de :total preguntas respondidas',
            'unanswered'      => ':count sin responder',
            'observations'    => '{1} 1 observación — pregunta :list|[2,*] :count observaciones — preguntas :list',
            'no_observations' => 'Sin observaciones',
            'observation'     => 'OBSERVACIÓN',
            // El aviso solo cuando hay algun «No». En la v1 salia siempre, y un
            // aviso que sale siempre deja de leerse.
            'notice'          => 'Cada «No» es una observación: no inicie el trabajo hasta resolverla con el supervisor HSE.',
            'untitled'        => 'Pregunta sin enunciado',
            'outside_catalog' => 'Respuesta a una pregunta que ya no está en esta versión del formato.',
        ],

        // ── EPP por trabajador ───────────────────────────────────────────
        // La v1 pintaba una cuadricula de trabajadores × items, apaisada y con
        // los nombres de los items girados en vertical a 6 px. DomPDF no sabe
        // girar texto, asi que aqui es un bloque por trabajador con sus items
        // en tres columnas — ver la cabecera del parcial.
        'person_checklist' => [
            'summary'                 => ':people trabajadores inspeccionados · :issues no conformidades',
            'number'                  => 'Nº',
            'worker'                  => 'Trabajador',
            'status'                  => 'Estado',
            // En qué quedó cada trabajador, al final de su fila. La palabra
            // acompaña siempre a la marca: una fotocopia en blanco y negro
            // tiene que seguir diciendo lo mismo.
            'status_bad'              => 'No conforme',
            'status_pending'          => 'Sin completar',
            'all_ok'                  => 'Sin observaciones',
            'unnamed_worker'          => 'Trabajador :number',
            'unknown_item'            => 'Ítem sin identificar',
            // Los rotulos de `config.extra`, buscados por el nombre de la
            // clave: una plantilla que añada una columna desde la interfaz se
            // pinta sola, con el codigo humanizado si no hay traduccion.
            'correction_measure'      => 'Medida de corrección',
            'deadline_date'           => 'Fecha límite',
            'correction_verification' => 'Verificación de la corrección',
            'answers' => [
                'compliant'      => 'Conforme',
                'not_applicable' => 'No aplica',
                'non_compliant'  => 'No conforme',
            ],
        ],

        // ── La leyenda de las cuadrículas (EPP e inspección de herramientas)
        // Las palabras salen de `config.answers` de la propia plantilla
        // —«Conforme» en el EPP, «Cumple» en las herramientas— y éstas son sólo
        // el respaldo para una plantilla que no las declare, más el hueco, que
        // no es una respuesta sino la falta de una.
        'legend' => [
            'ok'         => 'Conforme',
            'na'         => 'No aplica',
            'bad'        => 'No conforme',
            'unanswered' => 'Sin responder',
        ],

        // ── Inspección de herramientas (IHM) ─────────────────────────────
        // La v1 ponía el nombre de cada punto girado 90º en la cabecera y un
        // símbolo por celda (✔ / - / x) con leyenda al pie. DomPDF no gira
        // texto, así que las columnas van numeradas con su leyenda arriba; los
        // símbolos sí se recuperaron, con la fuente que trae la propia libreria
        // (ver `App\Services\FieldWork\Pdf\Simbolos`).
        'tool_checklist' => [
            'points'                  => 'Puntos de inspección',
            'number'                  => 'Nº',
            'tool'                    => 'Herramienta',
            'status'                  => 'Estado',
            'conforming'              => 'Conforme',
            'nonconforming'           => 'No conforme',
            'incomplete'              => 'Sin completar',
            'unanswered'              => 'Sin responder',
            'unnamed_point'           => 'Punto sin nombre',
            'unnamed_tool'            => 'Herramienta sin nombre',
            'code'                    => 'Cód.',
            'quantity'                => 'Cant.',
            'not_recorded'            => 'sin registrar',
            'correction_measure'      => 'Medida de corrección',
            'deadline_date'           => 'Fecha límite',
            'responsible'             => 'Responsable',
            'correction_verification' => 'Verificación de la corrección',
        ],

        'methods' => [
            'face_recognition' => 'Reconocimiento facial',
            'timeout_capture'  => 'Captura por tiempo de espera',
            'manual'           => 'Manual autorizada',
            'reused'           => 'Firma reutilizada',
            'migrated'         => 'Migrada del sistema anterior',
        ],


        // Pie de firmas formales del workspace.
        'formal_signers' => 'Firmas del workspace',

        // Pie de página: identificador con el que se verifica la entrega.
        'verify_id'    => 'Identificador de verificación',
        'generated_at' => 'Generado el',
        'page'         => 'Página',
    ],
];
