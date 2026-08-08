<?php

return [
    'approval_out_of_order' => 'Todavía no puedes aprobar: falta la firma de :roles, que va antes en el flujo.',

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
    'readonly_notice' => 'Este formato ya está confirmado: se puede consultar, pero no modificar.',
    'document'        => 'Documento',
    'attach'          => 'Adjuntar',
    'save'            => 'Guardar',
    'confirm'         => 'Confirmar formato',
    'mark_all'        => 'Marcar todo',

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
];
