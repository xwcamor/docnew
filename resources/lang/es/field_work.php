<?php

return [
    'approval_out_of_order' => 'Todavía no puedes aprobar: falta la firma de :roles, que va antes en el flujo.',

    // La regla de siempre: el que ejecuta declara primero lo que va a hacer y
    // el supervisor autoriza sobre esa declaración. Autorizar antes es firmar
    // en blanco. La dice el servidor al intentar firmar y la repite la ficha en
    // la fila bloqueada: una regla, una frase.
    'approval_needs_representative' => 'Todavía no puedes aprobar: falta designar al representante de los trabajadores. Nadie autoriza el trabajo hasta que alguien responda por el equipo que lo va a hacer.',

    // Pantalla de firma. Se lee con casco, a pleno sol y con la cámara abierta:
    // frases cortas, y cada una dice qué hacer, no qué pasa por dentro.
    'sign' => [
        'searching'          => 'Buscando un rostro…',
        'comparing'          => 'Comparando…',
        'evidence'           => 'Sin coincidencia: tomando la foto para revisión',
        'frame_face'         => 'Encuadra el rostro',
        'enrolling'          => 'Registrando cara…',
        'enroll_progress'    => 'Registrando cara: :done de :total',
        'no_face_registered' => 'Esta persona no tiene su cara registrada. Mantén el rostro encuadrado.',
        'enroll_failed'      => 'No se pudo registrar la cara. Inténtalo de nuevo con mejor luz.',
        'enroll_done'        => 'Cara registrada. Ahora firma.',
        'nobody_found'       => 'No se detectó a nadie frente a la cámara. No se registró nada.',
        'challenge_failed'   => 'La cara coincide pero no se completó el gesto. La firma queda registrada y pendiente de revisión del supervisor.',
        'failed'             => 'No se pudo completar la firma.',
        // Firma trazada. Se pide una vez y se reutiliza, como en la v1.
        'has_signature_on_file' => 'Ya tiene su firma registrada',
        'needs_signature'    => 'Falta registrar su firma',
        'draw_title'         => 'Firma aquí',
        'draw_hint'          => 'Es la primera vez que firma en el sistema. Su firma queda guardada y no se le volverá a pedir: a partir de ahora sólo la foto.',
        'draw_first'         => 'Primero hay que firmar en el recuadro.',
        'reuse_hint'         => 'Su firma ya está registrada y se usará ésa. Sólo hace falta la foto.',
        'replace'            => 'Actualizar mi firma',
        'clear'              => 'Borrar',
        'take_photo_and_sign'=> 'Tomar foto y firmar',
        'back_to_plan'       => 'Volver al plan',
        'back_to_plan_in'    => 'Volviendo al plan… :n',
        'no_target'          => 'No se sabe a quién firmar',
        'no_target_hint'     => 'Vuelve al plan y pulsa Firmar en la fila de la persona.',
        'signature_required' => ':name no tiene firma registrada: hay que trazarla antes de firmar el plan.',
        'turn_head'          => 'Gira la cabeza hacia un lado',
        'nod_head'           => 'Asiente con la cabeza',
        'back_center'        => 'Ahora vuelve a mirar al frente',
    ],

    // Pantalla de llenado de un formato del plan.
    'version'         => 'Versión',
    'missing'         => 'Falta completar',
    // Lo que falta se nombra por su etiqueta, no por el código del campo: el
    // aviso lo lee quien está rellenando el formato en obra. El adjunto no es
    // un campo y no tiene etiqueta, así que la suya va escrita aquí.
    'missing_attachment' => 'el archivo del formato',
    'missing_required' => 'Todavía falta por llenar: :fields. Rellénalo y vuelve a darle a Confirmar.',
    'readonly_notice' => 'Este formato ya está confirmado. Para cambiar algo hay que volver a abrirlo, y queda anotado quién lo hizo.',
    'reopen'          => 'Volver a editar',
    'reopen_title'    => '¿Volver a abrir este formato?',
    'reopen_help'     => 'Vuelve a borrador para poder corregirlo. Queda registrado en el historial: quién lo reabrió y cuándo. Habrá que confirmarlo otra vez.',
    'reopened'        => 'Formato abierto de nuevo. Corrige lo que haga falta y vuelve a confirmarlo.',
    // El servidor tambien lo dice, no solo la pantalla: hasta ahora el candado
    // de un formato confirmado vivia solo en el navegador.
    'confirmed_reopen_first' => 'Este formato está confirmado. Vuelve a abrirlo antes de cambiar nada.',
    'plan_closed'     => 'El plan está cerrado: sus formatos ya no se tocan.',
    'document'        => 'Documento',
    'attach'          => 'Adjuntar',
    'save'            => 'Guardar',
    'confirm'         => 'Confirmar formato',
    'mark_all'        => 'Marcar todo',

    // Avance de un campo compuesto: el índice de filas y las cabeceras
    // plegadas del EPP, el IHM y la matriz de riesgo.
    //
    // El estado siempre es una PALABRA, nunca sólo un color (docs/UI.md §5), y
    // «Faltan 3» dice lo mismo que la pastilla naranja pero además dice cuánto:
    // con siete trabajadores y 25 items, saber que a alguien le faltan tres es
    // la mitad del trabajo.
    'progress' => [
        'complete'      => 'Completo',
        'not_started'   => 'Sin empezar',
        'missing'       => 'Faltan :n',
        'nonconforming' => 'No conforme',
        'expand_all'    => 'Desplegar todo',
        'collapse_all'  => 'Plegar todo',
        'next'          => 'Siguiente: :name',
        'checks'        => ':done de :total controles',
        'people_done'   => ':done de :total trabajadores completos',
        'tools_done'    => ':done de :total herramientas completas',
        'hazards_rated' => ':done de :total peligros evaluados',
        'by_level'      => 'Alto: :high · Medio: :mid · Bajo: :low',
        // Nombre de respaldo de una fila que todavía no tiene el suyo: una
        // herramienta recién añadida no se llama de ninguna manera.
        'row'           => 'Fila :n',
        'hazard'        => 'Peligro :n',
        'index_people'  => 'Trabajadores del formato',
        'index_tools'   => 'Herramientas del formato',
        'index_hazards' => 'Peligros del formato',
    ],

    'status' => [
        'pending'   => 'Sin empezar',
        'draft'     => 'En borrador',
        'submitted' => 'Enviado',
        'confirmed' => 'Confirmado',
    ],

    // Campos de corrección: solo aparecen cuando algo sale no conforme.
    'correction_title' => 'Corrección',
    'extra' => [
        'correction_measure'      => 'Medida de corrección',
        'deadline_date'           => 'Fecha límite',
        'correction_verification' => 'Verificación de la corrección',
        'responsible'             => 'Responsable',
        'observation'             => 'Observación',
    ],

    // AST y PTF: actividad → peligro → control → severidad × probabilidad.
    'risk_matrix' => [
        'activity'     => 'Actividad',
        'danger'       => 'Peligro',
        'risk'         => 'Riesgo',
        'control'      => 'Control',
        'severity'     => 'Severidad',
        'probability'  => 'Probabilidad',
        'search'       => 'Buscar en el catálogo',
        'add_row'      => 'Añadir peligro',
        'remove_row'   => 'Quitar esta fila',
        'empty'        => 'Todavía no hay peligros. Añade el primero.',
        'row_incomplete' => 'Al peligro le falta: :fields. Un peligro evaluado se declara entero: qué puede pasar, qué consecuencia tiene y qué control se puso.',
        'no_risk'      => 'Sin evaluar',
        'level_alto'   => 'Riesgo alto',
        'level_medio'  => 'Riesgo medio',
        'level_bajo'   => 'Riesgo bajo',
    ],

    // EPP: una fila por trabajador de la cuadrilla.
    'person_checklist' => [
        'no_people' => 'El plan no tiene trabajadores asignados: añádelos para poder llenar este formato.',
    ],

    // IHM: una fila por herramienta.
    'tool_checklist' => [
        'tool'        => 'Herramienta',
        'add_tool'    => 'Añadir herramienta',
        'remove_tool' => 'Quitar esta herramienta',
        'empty'       => 'Todavía no hay herramientas. Añade la primera.',
    ],
    'signature_reason_placeholder' => 'Motivo (opcional)',
];
