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
        // Lo mismo pero sin saber por qué: la firma se guardó con su foto y no
        // vale hasta que la mire un supervisor. Dice qué pasa con la firma, no
        // qué falló la cámara: eso ya no lo puede arreglar quien lo lee.
        'pending_review'     => 'La firma queda registrada, pero no se pudo verificar la cara. La revisará el supervisor.',
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
        // Sólo sale cuando la firma quedó pendiente de revisión: la limpia se va
        // al plan sola. Aquí vivía también `back_to_plan_in` («Volviendo al
        // plan… :n»), la cuenta atrás del botón. Se quitó con ella: contaba un
        // detalle del funcionamiento que a nadie le sirve y cambiaba el texto
        // del botón cada segundo mientras se apuntaba con el dedo.
        'back_to_plan'       => 'Volver al plan',
        'no_target'          => 'No se sabe a quién firmar',
        'no_target_hint'     => 'Vuelve al plan y pulsa Firmar en la fila de la persona.',
        'signature_required' => ':name no tiene firma registrada: hay que trazarla antes de firmar el plan.',
        'left_pending'       => 'Firma registrada. Queda pendiente de revisión del supervisor.',
        'verified'           => 'Firma verificada.',
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
    // Lo dice el servidor si alguien intenta confirmar por la puerta de la API
    // con el formato a medias. Ya no nombra ningún botón: «vuelve a darle a
    // Confirmar» hablaba de un botón que ya no existe — hoy guardar y
    // confirmar son un solo «Guardar cambios».
    'missing_required' => 'Todavía falta por llenar: :fields.',
    // El aviso amable bajo la lista de faltantes. Guardar a medias no es un
    // error —en obra se llena por partes—, así que primero dice que el trabajo
    // no se perdió y después qué queda.
    'missing_help'    => 'Lo que guardaste ya está guardado y el formato sigue en borrador. Completa lo que falta y vuelve a pulsar Guardar cambios.',
    // La palabra del estado junto a la etiqueta del campo que falta: el rojo
    // nunca va solo (docs/UI.md §5).
    'field_missing'   => 'Sin llenar',
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
    'attach_many'     => 'Adjuntar :count archivos',
    'attach_drag_strong'   => 'Arrastra aquí los archivos',
    'attach_drag_or_click' => 'o toca para buscarlos',
    'attach_formats_hint'  => 'Fotos (JPG, PNG, WEBP) o PDF. Hasta 8 MB cada uno.',
    'attach_bad_type' => 'no es una foto ni un PDF',
    'attach_too_big'  => 'pesa más de 8 MB',
    'attach_rejected' => 'Estos archivos no se van a subir',
    'no_attachments'  => 'No se adjuntó ningún archivo.',
    'detach_confirm'  => '¿Quitar este archivo del formato?',
    'detached_flash'  => 'Archivo quitado.',
    'attach_full'     => '{1} Este campo admite un solo archivo y ya lo tiene. Quítalo para poner otro.|[2,*] Este campo admite :max archivos y ya los tiene. Quita alguno para poner otro.',
    'attach_max_files' => '«:label» admite como mucho :max archivos.',
    'attach_field_mimes' => ':name no vale aquí: este campo solo admite :mimes.',
    'signature_save'   => 'Guardar firma',
    'signature_redo'   => 'Volver a firmar',
    'signature_redo_confirm' => '¿Borrar esta firma y volver a firmar?',
    'signature_none'   => 'Sin firmar.',
    // El ÚNICO botón de acción del pie. Eran dos —«Confirmar formato» y
    // «Guardar cambios»— y el dueño del producto lo dijo tal cual: nadie
    // guarda para después volver a confirmar. Ahora guardar intenta confirmar
    // en el mismo gesto y el servidor decide; la clave `confirm` se fue con el
    // botón.
    'save'            => 'Guardar cambios',
    // Los dos finales de ese botón, contados por el servidor.
    'saved_partial'   => 'Cambios guardados.',
    'saved_confirmed' => 'Formato guardado y confirmado.',
    // Flashes que vivían escritos a pelo en el controlador («Documento
    // adjuntado.», «Formato confirmado.»): en inglés salían en castellano.
    'attached_flash'  => '{1} Documento adjuntado.|[2,*] :count documentos adjuntados.',
    'confirmed_flash' => 'Formato confirmado.',
    'mark_all'        => 'Marcar todo',

    // La salida del pie, la misma en los dos estados: llenando y mirando un
    // formato ya confirmado. Nombra el destino en vez de decir «Cancelar»,
    // que significa deshacer, y aquí no se deshace nada: lo que ya se guardó se
    // queda. Es el texto que usa la pantalla de firma para este mismo viaje.
    'back_to_plan'    => 'Volver al plan',
    // Sólo salta si hay algo tecleado sin guardar. Dice qué se pierde y qué no,
    // que es lo que hace falta saber para contestar.
    'leave_title'     => '¿Salir sin guardar?',
    'leave_help'      => 'Lo que escribiste desde la última vez que guardaste se pierde. Lo que ya guardaste sigue ahí.',
    'leave_discard'   => 'Salir sin guardar',
    'leave_stay'      => 'Seguir aquí',

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
        // El banco de preguntas no tiene índice de filas: su avance es esta
        // línea. Decía «3/25» a secas — un quebrado sin sustantivo no dice
        // 3 de 25 QUÉ, y los demás campos compuestos sí lo dicen.
        'questions_done' => ':done de :total preguntas respondidas',
        // Lo que dice un campo compuesto obligatorio cuando un guardado lo
        // dejó pendiente (la prop `faltante` del contrato con FormFill).
        // No un rojo genérico sobre el bloque: la cuenta de lo concreto que
        // falta, que es lo único accionable. La palabra acompaña al color
        // (docs/UI.md §5).
        'required_hazards'   => '{0} Obligatorio: añade una actividad y evalúa sus peligros.|{1} Obligatorio: falta 1 peligro por evaluar.|[2,*] Obligatorio: faltan :count peligros por evaluar.',
        'required_people'    => '{1} Obligatorio: falta completar 1 trabajador.|[2,*] Obligatorio: falta completar :count trabajadores.',
        'required_tools'     => '{0} Obligatorio: añade al menos una herramienta.|{1} Obligatorio: falta completar 1 herramienta.|[2,*] Obligatorio: faltan :count herramientas por completar.',
        'required_questions' => '{1} Obligatorio: falta responder 1 pregunta.|[2,*] Obligatorio: faltan :count preguntas por responder.',
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

    // AST y PTF: el documento es una lista de ACTIVIDADES y, dentro de cada
    // una, sus peligros — peligro → riesgo → control → probabilidad →
    // severidad → nivel. Ése es el orden de las columnas de la v1.
    'risk_matrix' => [
        'activity'     => 'Actividad',
        'danger'       => 'Peligro',
        'risk'         => 'Riesgo',
        'control'      => 'Control',
        'severity'     => 'Severidad',
        'probability'  => 'Probabilidad',
        // Sexta columna de la tabla vieja. No se teclea: sale de la matriz.
        'level'        => 'Nivel',
        // Era «Buscar en el catálogo» cuando esto fue un select cerrado. Se
        // escribe libre, como en la v1: el catálogo sugiere, no manda.
        'search'       => 'Escribe, o elige una sugerencia',
        'add_row'      => 'Añadir peligro',
        'remove_row'   => 'Quitar esta fila',
        // La papelera de cada fila del modo tabla pregunta antes, como el
        // confirm del link_to_remove_association de la v1: borra una fila
        // entera del documento y un toque con guantes se va fácil.
        'remove_row_confirm' => '¿Quitar este peligro?',
        // La palabra junto al rojo de una celda vacía del modo tabla: ahí no
        // hay etiqueta de campo que teñir y el color nunca va solo (UI.md §5).
        'cell_missing' => 'Falta',
        // Cabecera de cada bloque, como el «Actividad 1» del formato de papel.
        'activity_n'    => 'Actividad :n',
        'activity_hint' => 'Ponle nombre a la actividad: es el título de los peligros que van debajo.',
        'add_activity'  => 'Añadir actividad',
        'remove_activity' => 'Quitar esta actividad',
        // Quitar la actividad se lleva sus peligros por delante, así que se
        // dice cuántos son antes de hacerlo.
        'remove_activity_confirm' => '{0} ¿Quitar esta actividad?|{1} ¿Quitar esta actividad y su peligro?|[2,*] ¿Quitar esta actividad y sus :count peligros?',
        'empty'        => 'Todavía no hay actividades. Añade la primera.',
        'row_incomplete' => 'Al peligro le falta: :fields. Un peligro evaluado se declara entero: qué puede pasar, qué consecuencia tiene y qué control se puso.',
        // La misma regla del servidor (`exigirPeligroEntero`), dicha fila a
        // fila y ANTES de guardar: el usuario descubría el peligro a medias
        // recién en el aviso del guardado, lejos de la fila del problema.
        // `row_incomplete` es la frase larga del servidor; aquí manda la
        // corta, pegada a la fila, con las columnas que faltan por su nombre.
        'row_missing'  => 'Falta: :fields',
        // La palabra de la pastilla roja en la cabecera y en el índice: una
        // fila empezada a puntuar y sin terminar. No es «Sin evaluar» —eso es
        // una fila legítima que nadie tocó— ni un nivel: es un bloqueo.
        'incomplete'   => 'Incompleto',
        'no_risk'      => 'Sin evaluar',
        'level_alto'   => 'Riesgo alto',
        'level_medio'  => 'Riesgo medio',
        'level_bajo'   => 'Riesgo bajo',
    ],

    // EPP: una fila por trabajador del plan.
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
    // Coincidencia de la cara, en porcentaje. Por dentro se guarda una
    // distancia —0 es la misma cara— que se lee al reves de como se piensa.
    'match_percent' => 'Coincidencia de la cara: :percent%',
    'signature_reason_placeholder' => 'Motivo (opcional)',
];
