<?php

return [
    'singular'      => 'Plan de trabajo',
    'plural'        => 'Planes de trabajo',
    'record'        => 'plan de trabajo',
    'records'       => 'planes de trabajo',
    'new'           => 'Crear plan de trabajo',
    'id'            => 'N°',

    'index_title'    => 'Planes de trabajo',
    'index_subtitle' => 'Trabajos programados en obra, con su empresa contratista, sus trabajadores y sus formatos de seguridad.',
    'create_title'   => 'Crear plan de trabajo',
    'create_subtitle'=> 'Registra el trabajo del día: quién lo ejecuta, de qué tipo es y dónde se hace.',
    'edit_title'     => 'Editar plan de trabajo',
    'delete_title'   => 'Eliminar plan de trabajo',
    'show_title'     => 'Plan de trabajo — Información',
    'trash_title'    => 'Papelera de planes de trabajo',
    'form_create_hint' => 'Registra el trabajo del día: quién lo ejecuta, de qué tipo es y dónde se hace.',
    'empty_hint'      => 'Crea el primer plan de trabajo o importa un lote desde Excel para empezar.',

    // ── Campos ──────────────────────────────────────────────────────────────
    'code'                 => 'Código',
    'num_os'               => 'Orden de servicio',
    // Se dice, en vez de esconder el campo o poner un guión: un trabajo sin
    // orden es una pregunta abierta —¿no la tiene, o no se apuntó?— y un hueco
    // vacío no la responde.
    'num_os_none'          => 'Este trabajo no tiene orden de servicio',
    'num_os_help'          => 'Número de la orden de servicio del cliente.',
    'num_os_placeholder'   => 'Ej: OS-2024-1187',
    'description'          => 'Descripción del trabajo',
    'description_help'     => 'Qué se va a hacer, en las palabras del supervisor. Es lo que se busca cuando no se recuerda el código.',
    'description_placeholder' => 'Ej: Mantenimiento preventivo de celda de media tensión',
    'company'              => 'Empresa',
    'company_help'         => 'Empresa contratista que ejecuta el trabajo.',
    'work_type'            => 'Tipo de trabajo',
    'work_type_help'       => 'Determina qué formatos de seguridad exige el plan.',
    'work_location'        => 'Sede',
    'work_location_help'   => 'Instalación donde se ejecuta el trabajo.',
    'workstation'          => 'Puesto',
    'workstation_help'     => 'Puesto dentro de la sede. Se elige después de la sede.',
    'workstation_needs_location' => 'Elige primero una sede',
    'work_area'            => 'Área',
    'work_area_help'       => 'Área de la instalación intervenida.',
    // Llevan hora, y por eso lo dicen: es lo que las distingue de una fecha de
    // calendario y lo que hace que «Tiempo trabajado» signifique algo.
    'date_start'           => 'Fecha y hora de inicio',
    'date_end'             => 'Fecha y hora de fin',
    'period_work'          => 'Período de trabajo',
    'worked_time'          => 'Tiempo trabajado',
    'worked_time_open'     => 'En curso',
    'is_done'              => 'Estado',
    'is_done_help'         => 'Un plan está terminado cuando todos sus formatos están confirmados y todas las firmas obligatorias fueron levantadas.',
    'cannot_edit_closed'   => 'Este plan ya se cerró: es un documento del archivo y no se edita. Si hay que corregirlo, reábrelo primero.',
    'is_closed'            => 'Cerrado',
    'people_count'         => 'Trabajadores',
    'forms_count'          => 'Formatos',
    'registered_by'        => 'Registrado por',

    'state_done'     => 'Terminado',
    'state_pending'  => 'Pendiente',
    'state_locked'   => 'Bloqueado',
    'state_unlocked' => 'Sin bloquear',

    'section_work'     => 'Trabajo y ubicación',
    'section_schedule' => 'Fechas',

    // ── La ficha del plan: resumen (por defecto) y vista larga ──────────────
    // El supervisor abre la ficha con el casco puesto: primero necesita saber
    // qué trabajo es y cómo va. Los identificadores y las fechas de registro
    // están en la vista larga, que es donde se buscan cuando se buscan.
    'view_summary'  => 'Resumen',
    'view_full'     => 'Todos los datos',
    'view_hint'     => 'Cambiar de vista',

    'summary_work'    => 'El trabajo',
    'summary_no_desc' => 'Sin descripción',
    'summary_where'   => 'Dónde',
    'summary_when'    => 'Cuándo',
    'summary_same_day'=> 'Un solo día',

    'progress_title'      => 'Cómo va',
    'progress_forms'      => 'Formatos llenos',
    'progress_signatures' => 'Trabajadores que firmaron',
    'progress_approvals'  => 'Aprobaciones firmadas',
    'progress_count'      => ':done de :total',
    'progress_empty'      => 'Nada asignado',

    'missing_title'      => 'Falta para cerrarlo',
    'missing_crew'       => 'Asignar los trabajadores: todavía no hay ninguno.',
    'missing_forms'      => '{1} Llenar y confirmar 1 formato.|[2,*] Llenar y confirmar :count formatos.',
    'missing_signatures' => '{1} Recoger la firma de 1 trabajador.|[2,*] Recoger las firmas de :count trabajadores.',
    'missing_approvals'  => '{1} Falta 1 aprobación obligatoria: :roles.|[2,*] Faltan :count aprobaciones obligatorias: :roles.',
    'missing_none'       => 'No falta nada: el plan ya se puede dar por terminado.',
    'missing_done'       => 'El plan está terminado.',

    'technical_title' => 'Datos del registro',

    'filter_search'            => 'Código, OS o descripción',
    'search_placeholder'       => 'Buscar por código, OS o descripción…',
    'trash_search_placeholder' => 'Buscar por código u orden de servicio…',

    'bulk_mark_done' => 'Marcar terminado',
    'bulk_reopen'    => 'Reabrir',

    'edit_hint'   => 'Modificar este registro',
    'delete_hint' => 'Eliminar (queda en papelera)',
    'restore_hint'=> 'Volverá a estar disponible en el listado principal.',

    'created' => 'Plan de trabajo creado.',
    'saved'   => 'Plan de trabajo actualizado.',
    'deleted' => 'Plan de trabajo eliminado.',

    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Export
    'export_filename'           => 'exportacion_planes_de_trabajo',
    'import_template_filename'  => 'plantilla-planes-de-trabajo.xlsx',
    'export_title'              => 'Reporte de planes de trabajo',
    'export_limit_exceeded'     => 'El export en :format excede el límite (:count filas vs :limit máximo). Usa CSV para datasets grandes (sin límite).',
    'export_format_limit_hint'  => 'Máximo :limit filas para este formato. Usa CSV para datasets grandes.',
    'export_no_limit_hint'      => 'Sin límite — recomendado para datasets grandes.',

    // Validación
    'code_required'            => 'El código del plan es obligatorio.',
    'code_unique'              => 'Ya existe un plan de trabajo con este código.',
    'description_required'     => 'La descripción del trabajo es obligatoria.',
    'company_required'         => 'Indica qué empresa ejecuta el trabajo.',
    'work_type_required'       => 'Indica el tipo de trabajo.',
    'work_location_required'   => 'Indica la sede donde se ejecuta el trabajo.',
    'date_end_after'           => 'La fecha de fin no puede ser anterior a la de inicio.',
    'is_done_required'         => 'El campo estado es obligatorio.',
    'import_super_blocked'     => 'Un super sin workspace asignado no puede importar (el match por código podría actualizar registros de otro workspace).',

    // Edit All
    'edit_all_title'    => 'Planes de trabajo — Editar todo',
    'edit_all_subtitle' => 'Corrige la orden de servicio y el estado de varios planes a la vez. El código no se edita aquí: identifica al plan.',
    'edit_all_changes'  => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all' => 'Guardar todo',
    'edit_all_discard'  => 'Descartar cambios',
    'edit_all_no_results' => 'No hay planes de trabajo que coincidan con el filtro.',

    'table_headers' => [
        'editable_num_os' => 'Orden de servicio (editable)',
        'editable_status' => 'Estado (editable)',
    ],

    // ── Armado del plan: cuadrilla, formatos y aprobadores ──────────────────
    'setup_blocked_done'   => 'El plan está terminado: ya no se le cambian los trabajadores, los formatos ni los aprobadores.',
    'setup_blocked_closed' => 'El plan está cerrado: ya no se le cambian los trabajadores, los formatos ni los aprobadores.',
    'setup_blocked_hint'   => 'Este plan es solo de consulta.',
    'state_closed'         => 'Cerrado',
    // Lo contrario de cerrado, para el filtro del listado. No es «desbloqueado»:
    // un plan abierto es el que todavía se está trabajando.
    'state_open'           => 'Abierto',

    // «Trabajadores» a secas. El sistema anterior decía «del Proveedor» porque
    // allí sólo entraban los de la contratista, pero la empresa principal
    // también pone gente en sus planes. «Cuadrilla» la inventé yo.
    'crew_title'    => 'Trabajadores',
    'crew_summary'  => '{0} Nadie firmó todavía|{1} 1 de :total firmó|[2,*] :signed de :total firmaron',
    'crew_empty'    => 'Todavía no hay nadie. Escanea el documento del primer trabajador.',
    'crew_empty_why' => 'Sin trabajadores no se puede llenar ningún formato, no hay a quién designar representante y no se puede firmar. Es lo primero del plan.',
    'crew_pending_tag' => 'Faltan los trabajadores',
    'crew_add'      => 'Añadir trabajador',
    'crew_add_title'=> 'Añadir trabajador',
    // La misma frase que en obra: se escanea el DNI, no se busca por apellido.
    'crew_search_placeholder' => 'Escanea o escribe el documento del trabajador…',
    'crew_search_hint' => '{1} Escribe 1 carácter o más del documento.|[2,*] Escanea el documento o escribe sus :count dígitos: se añade solo.',
    'crew_keep_typing' => 'Hay documentos que empiezan así. Sigue escribiendo hasta el final.',
    // El documento está cifrado en la base y sólo se puede buscar entero (ver
    // App\Support\DocumentoBuscable). Sin este aviso, teclear siete de las ocho
    // cifras de un DNI contestaría «no está registrado» sobre alguien que sí lo
    // está, y el siguiente gesto en la puerta sería darlo de alta por segunda vez.
    'crew_search_exact' => 'La búsqueda es por el documento completo: escanéalo o escríbelo entero.',
    'crew_create_person' => 'Dar de alta a este trabajador',
    'crew_create_person_title' => 'Nuevo trabajador',
    'crew_create_person_help' => 'Sólo lo que el plan no sabe. La empresa y el país se toman del plan, y en cuanto se guarde entra en la lista de trabajadores.',
    'crew_create_person_ok' => 'Dar de alta y añadir',
    'crew_person_created' => ':name queda dado de alta y añadido al plan.',
    'crew_person_exists' => 'Ese documento ya es de :name. Búscalo por su documento en vez de darlo de alta otra vez.',
    'crew_no_results'         => 'Ese documento no está registrado todavía. Compruébalo entero antes de dar de alta a nadie.',
    'crew_remove'   => 'Quitar del plan',
    'crew_remove_confirm' => '¿Quitar a :name de este plan?',
    // Si la persona tiene la cara registrada se ve en el módulo de Trabajadores,
    // que es donde se registra. En la ficha del plan no pinta nada: ahí lo que
    // importa es si firmó y cuándo.
    'crew_enrolled'     => 'Cara registrada',
    'crew_not_enrolled' => 'Sin cara registrada',
    'crew_signed'       => 'Firmó',
    'done_at_signed' => 'Firmado el :when',
    'done_at_completed' => 'Completado el :when',
    'crew_signed_at'    => 'Firmado el :when',
    'crew_pending'      => 'Falta su firma',

    // Cómo se produjo la firma. La hora dice CUÁNDO; esto dice si el servidor
    // reconoció la cara. Una firma verificada y una que se capturó porque NO
    // reconoció salían iguales en la ficha. Es un hecho, no una tarea: la
    // firma vale igual y no se promete ninguna revisión — no la hay.
    'sign_how'                 => 'Cómo firmó',
    'sign_face_recognition'    => 'Reconocimiento facial',
    'sign_face_recognition_hint' => 'El servidor comparó la cara con la biometría de :name y coincidió.',
    'sign_timeout_capture'     => 'Sin reconocer',
    'sign_timeout_capture_hint' => 'No coincidió con la biometría de :name. Se guardó la foto como constancia.',
    'sign_manual'              => 'Firma manual',
    'sign_manual_hint'         => 'Autorizada a mano, con motivo. No hubo comparación de la cara.',
    'sign_migrated'            => 'Del sistema anterior',
    'sign_migrated_hint'       => 'Firma traída del sistema anterior. Allí no se guardaba con qué se comprobó.',
    'sign_reused'              => 'Firma reutilizada',
    'sign_reused_hint'         => 'Se reutilizó la comprobación que :name ya pasó en este mismo plan.',

    // El rastro de la firma, que se abre al pulsar la cara. Estaba guardado
    // desde el principio y no se enseñaba en ninguna pantalla.
    'sign_audit_open'      => 'Ver la firma: cara y rastro',
    // La misma ficha sin la foto (admin): se promete lo que se va a enseñar.
    'sign_audit_open_trail' => 'Ver el rastro de la firma',
    'sign_audit_signed_at' => 'Firmó',
    'sign_audit_match'     => 'Coincidencia de la cara',
    'sign_audit_ip'        => 'Desde la IP',
    'sign_audit_device'    => 'Dispositivo',
    'sign_audit_coords'    => 'Dónde',
    'sign_audit_browser'   => 'Navegador',
    'sign_audit_reason'    => 'Motivo de la firma manual',
    // El dato en crudo detrás del resumen: el user-agent entero y el UUID
    // completo del aparato. Ilegibles, y a la vez lo único que vale si un
    // día hay que discutir una firma.
    'sign_audit_full'      => 'ver completo',
    'sign_audit_map_open'  => 'abrir el mapa',
    // Por qué a una firma importada le falta medio rastro. El sistema
    // anterior guardaba IP, navegador, aparato y coordenadas SOLO de las
    // firmas de trabajador; una aprobación era una casilla y una imagen,
    // sin una columna de rastro. No se perdió al migrar: nunca se registró.
    'crew_sign_hint'    => 'Firmar por :name con reconocimiento facial',
    'crew_added'    => ':name se añadió al plan.',
    'crew_removed'  => 'El trabajador se quitó del plan.',
    'crew_already_assigned'     => ':name ya está en este plan.',
    // El motivo y nada más. Antes seguía explicando que la firma prueba que
    // estuvo en la obra y recibió la charla: eso es doctrina de seguridad, no
    // interfaz, y quien usa la pantalla ya lo sabe mejor que el sistema.
    'crew_signed_cannot_remove' => 'No se puede quitar a :name: ya firmó el plan.',

    'forms_title'    => 'Documentos',
    // La versión, en corto: «v3». La palabra entera se comería la línea
    // del subtítulo, y el número al lado del nombre ya se entiende.
    'forms_version_short' => 'v:n',
    'forms_not_in_plan'  => 'No se exige en este plan',
    'forms_toggle_hint'  => 'Exigir o no el formato :code en este plan',
    'forms_open_hint'    => 'Abrir el :code para llenarlo',
    // Con el plan cerrado el documento se mira y no se toca, y el botón lo dice
    // desde el verbo: «ver», no «abrir para llenar».
    'forms_view_hint'    => 'Ver el :code',
    'forms_pdf_hint'     => 'Descargar el :code en PDF',
    'forms_subtitle' => 'Los pone el tipo de trabajo. Aquí se suma o se quita uno.',
    'forms_summary'  => '{0} Ninguno completado|[1,*] :done de :total completados',

    // Lo que salio mal en un formato. Es el entero `observations` de la v1, que
    // los cuatro formatos recalculaban solos y era lo que el supervisor leia de
    // un vistazo en la ficha. Un EPP confirmado con tres arneses en mal estado
    // no es lo mismo que uno confirmado y limpio.
    // Todos los papeles del plan de golpe: el `plan_exports_controller`
    // de la v1, que era como se mandaba una jornada fuera.
    'export_zip' => 'Descargar todo',
    'export_zip_hint' => 'Baja los documentos completados del plan en un solo ZIP, uno por PDF. Es lo que se manda al cliente o a una inspección.',
    'export_zip_empty' => 'Todavía no hay ningún documento completado que descargar.',

    'findings_count' => '{1} 1 observación|[2,*] :count observaciones',
    // El hueco donde a veces sale un aviso rojo y a veces no sale nada se lee
    // como «todavía no lo han mirado». Esto es un resultado, y es el bueno.
    'findings_none'  => 'Sin observaciones',
    'findings_hint'  => '{1} Una respuesta salió no conforme. El formato se cierra igual: lo que no se puede es cerrarlo sin que conste.|[2,*] :count respuestas salieron no conformes. El formato se cierra igual: lo que no se puede es cerrarlo sin que conste.',
    'forms_findings' => '{1} 1 observación|[2,*] :count observaciones',
    'forms_empty'    => 'El tipo de trabajo de este plan no exige ningún documento.',
    'forms_add'      => 'Añadir formato',
    'forms_add_title'=> 'Añadir un formato a este plan',
    'forms_open'     => 'Abrir',
    'forms_pdf'      => 'PDF',
    'forms_remove'   => 'Quitar del plan',
    'forms_remove_confirm'   => '¿Quitar el formato :code de este plan?',
    'forms_source_work_type' => 'Del tipo de trabajo',
    'forms_source_extra'     => 'Añadido a este plan',
    'forms_source_submitted' => 'Fuera del estándar',
    'forms_required'  => 'Obligatorio',
    'forms_optional'  => 'Opcional',
    'forms_none_left' => 'No queda ningún formato publicado por añadir.',
    'form_added'      => 'El formato :code se añadió al plan.',
    'form_removed'    => 'El formato :code se quitó del plan.',
    'form_already_required'     => 'El plan ya exige el formato :code.',
    'form_not_published'        => 'El formato :code todavía no está publicado: no se puede llenar.',
    'form_filled_cannot_remove' => 'No se puede quitar el formato :code: ya tiene respuestas, adjuntos o firmas. Vaciarlo borraría el documento de seguridad de ese día.',
    // Lo exige el catálogo, no este plan. Se dice dónde se cambia, porque un
    // «no puedes» sin salida es lo que hace que la gente busque un atajo.
    'form_required_by_work_type' => 'El formato :code es obligatorio para los trabajos de tipo :type y no se quita de un plan suelto. Si ya no debe exigirse, cámbialo en el tipo de trabajo.',

    // «Flujo de Aprobaciones» es como lo llama el sistema anterior, y describe
    // mejor lo que es: una secuencia fija, no una lista que se edita.
    'approvals_title'    => 'Flujo de aprobaciones',
    'approvals_subtitle' => 'Se firman en orden. Escribe el documento del firmante para asignarlo.',
    'approvals_summary'  => '{0} Ninguna firmada|[1,*] :done de :total firmadas',
    'approvals_empty'    => 'Este plan no tiene aprobadores definidos.',
    'approvals_add'      => 'Añadir aprobador',
    'approvals_add_title'=> 'Añadir un aprobador',
    'approval_role'      => 'Rol que aprueba',
    'approval_person'    => 'Persona',
    'approval_unassigned'=> 'Sin asignar',
    'approval_required'  => 'Obligatoria',
    'approval_optional'  => 'Opcional',
    'approval_approved'  => 'Aprobado',
    'approval_pending'   => 'Pendiente',
    // «Bloqueada» decia «Pendiente», la misma palabra que una que si se puede
    // firmar. Dos estados distintos con el mismo nombre no son un estado.
    'approval_blocked'   => 'En espera',
    'approval_added'     => 'Aprobador añadido al plan.',
    'approval_role_taken'=> 'Este plan ya tiene una aprobación de :role.',
    'approval_rules_empty' => 'No quedan roles de aprobación libres para este plan.',

    // Asignar y firmar
    'approval_assign'    => 'Asignar firmante',
    'approval_change_hint' => 'Cambiar quién firma como :role',
    'approval_assign_hint' => 'Escanea o escribe el documento. Si la persona existe, sale su nombre.',
    'approval_assigned'  => ':name queda asignado como firmante.',
    'approval_change'    => 'Cambiar',
    'approval_sign'      => 'Firmar',
    'approval_no_one_with_role' => 'Ningún :role con ese documento. Comprueba que la persona tenga ese rol en su ficha.',
    'approval_wrong_role' => ':name no es :role. Sólo puede firmar esa aprobación quien tenga ese rol en su ficha de trabajador.',
    'approval_person_taken' => ':name ya firma otro rol de este plan. Una misma persona no cubre dos firmas.',
    'approval_signed_cannot_reassign' => 'Esta aprobación ya está firmada: no se cambia el firmante. La firma es la prueba de quién se hizo responsable.',

    // ── Quién responde por los trabajadores ──────────────────────────────────
    //
    // Antes era una fila más del flujo de aprobaciones, la del «ejecutante», y
    // de ahí salían los textos de arriba. Dejó de serlo: no recogía ninguna
    // firma propia —apuntaba a alguien que ya había firmado como trabajador— y
    // viviendo en el flujo se podía borrar o volver opcional desde las reglas
    // del país, dejando un plan con gente en la obra y sin nadie que
    // respondiera por ella.
    //
    // Se dice «trabajadores», no la palabra que me inventé yo (docs/UI.md §2).
    'representative'        => 'Representante de los trabajadores',
    // Con la empresa dicha por su nombre corto, que es como se pide en obra.
    // Sin articulo: «Representante de SERCE», no «de la SERCE» — el nombre de
    // una empresa no lleva articulo delante, y el dueño lo corrigio al verlo.
    // La forma generica de arriba queda de respaldo para cuando no hay nombre.
    'representative_of'     => 'Representante de :company',
    // Se dice DENTRO del aviso de que falta, y solo cuando ya se puede elegir:
    // suelto encima de la tarjeta era un parrafo gris que nadie leia, y delante
    // de una lista donde todavia no ha firmado nadie, «sale de los que ya
    // firmaron» confunde en vez de ayudar.
    'representative_help'   => 'Sale de los que ya firmaron y puede ser cualquiera del equipo: no hace falta que sea jefe ni que tenga un cargo especial.',
    'representative_none'   => 'Falta designar al representante',
    'representative_none_why' => 'Nadie autoriza el trabajo hasta que alguien responda por el equipo que va a hacerlo. Es lo único que falta para que el flujo de aprobaciones se pueda firmar.',
    'representative_pending'  => 'Falta',
    // «Designado», no «Aprobado»: aqui nadie aprueba nada. Se elige a quien
    // responde, y la firma que vale es la que ya dio como trabajador.
    'representative_done'     => 'Designado',
    'representative_designate' => 'Designar',
    'representative_change' => 'Cambiar',
    // Encabeza la lista de candidatos, que ahora sale directamente en vez de
    // detras de un boton: el conjunto es cerrado y pequeño —los de este plan
    // que ya firmaron, pintados justo encima— asi que se enseña y se pulsa.
    // Buscarlos tecleando el documento era mandar a alguien a buscar por DNI a
    // media docena de personas que ya estan en pantalla.
    'representative_pick'   => 'Elige quién responde por el equipo. Sale de los que ya firmaron y puede ser cualquiera: no hace falta que sea jefe.',
    'representative_pick_other' => 'Elige a otro de los que ya firmaron.',
    // No es lo mismo que falte designarlo que que no haya a quien: con el
    // equipo entero sin firmar no hay candidatos, y por eso no sale el botón.
    'representative_needs_signature' => 'Todavía no ha firmado nadie',
    'representative_needs_signature_why' => 'El representante sale de los trabajadores que ya firmaron: en cuanto haya una firma se podrá designar.',
    // El aviso que encabeza el flujo cuando esta bloqueado, y el boton que
    // lleva a lo unico que hay que hacer para desbloquearlo.
    'approvals_blocked_title' => 'Falta designar al representante de los trabajadores',
    'approvals_blocked_body'  => 'Nadie autoriza el trabajo hasta que alguien responda por el equipo que lo va a hacer. Hasta entonces no se asignan firmantes ni se firma.',
    'approvals_blocked_cta'   => 'Ir a designarlo',
    'approvals_blocked_tag'   => 'En espera',
    'representative_no_results' => 'Nadie con ese documento entre los que ya firmaron. El representante sale de los trabajadores de este plan que ya han firmado.',
    'representative_assigned' => ':name queda como representante de los trabajadores.',
    'representative_not_in_crew' => ':name no está en este plan. El representante tiene que ser uno de los trabajadores que salen a la obra.',
    'representative_must_sign_first' => ':name todavía no ha firmado en este plan. El representante sale de los que ya firmaron: esa firma, con su foto y su hora, es la que vale.',

    // Lo que le falta al plan para cerrarse solo. Las dos condiciones del
    // sistema anterior, ni una más.
    'close_needs_corrections' => '{1} Verificar la corrección de 1 observación.|[2,*] Verificar la corrección de :count observaciones.',
    'close_still_missing' => 'Todavía no se puede dar por terminado. Falta: :fields',
    'reopened' => 'Plan reabierto: ya puedes corregirlo. Cuando acabes, pulsa «Dar por terminado».',
    'marked_done' => 'Plan dado por terminado.',
    'state_reopened' => 'Reabierto',
    'views_label'    => 'Ver por estado',
    'filter_reopened'      => 'Reabierto',
    'filter_never_reopened' => 'Nunca reabierto',
    'reopen' => 'Reabrir',
    'reopen_hint' => 'Deja editables otra vez el plan, sus trabajadores, sus formatos y su representante. Queda anotado quién lo reabrió.',
    'reopen_confirm' => 'El plan volverá a estar en curso y se podrá modificar. Queda registrado quién lo reabrió y cuándo.',
    'mark_done' => 'Dar por terminado',
    'mark_done_hint' => 'Vuelve a cerrar el plan, si ya no falta nada.',
    'reopened_by' => 'Reabierto por :name el :when',
    'close_needs_date_end'  => 'Falta la hora de fin del trabajo.',
    // Literalmente los mensajes de la v1: «Debe tener al menos 1 trabajador» /
    // «...1 documento». Allí impedían guardar; aquí impiden cerrar, porque el
    // plan se arma después de crearse.
    'close_needs_crew'      => 'Debe tener al menos 1 trabajador.',
    'close_needs_forms'     => 'Debe tener al menos 1 formato.',
    'close_needs_signatures'  => '{1} Falta la firma de 1 trabajador.|[2,*] Faltan las firmas de :count trabajadores.',
    'close_needs_forms_done'  => '{1} Falta confirmar 1 formato.|[2,*] Faltan :count formatos por confirmar.',
    'close_needs_approvals' => '{1} Falta 1 aprobación obligatoria.|[2,*] Faltan :count aprobaciones obligatorias.',
    'close_needs_representative' => 'Falta designar al representante de los trabajadores.',

    'approval_waits_prior' => 'Espera la firma de :role.',

    // Sin «trabajador»: ese rol dejó de firmar aprobaciones el día que el
    // representante salió del flujo, y la migración borró sus reglas.
    'approver_role' => [
        'supervisor'     => 'Supervisor',
        'hse_supervisor' => 'Supervisor HSE',
    ],

    'field_work_title'    => 'Trabajo en obra',
    'field_work_subtitle' => 'Las pantallas que se usan en la tablet, en el sitio.',
    'field_work_forms'    => 'Llenar formatos',
    'field_work_sign'     => 'Firmar con reconocimiento facial',

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Planes de trabajo',
        'step1_body'  => 'Aquí está el trabajo del día. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca por código, orden de servicio o descripción, y filtra por empresa, tipo de trabajo, sede, estado y fechas.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic. Cada usuario tiene las suyas propias.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'De entrada se muestran solo las columnas que sirven en una tablet en obra. Aquí sumas sede, área, trabajadores o descripción cuando trabajas en escritorio.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — se te notificará cuando esté listo. Importa desde Excel/CSV con vista previa antes de confirmar.',
        'step6_title' => 'Editar muchos a la vez',
        'step6_body'  => '"Editar todo" permite corregir la orden de servicio y el estado de varios planes juntos, y guardarlos de una sola vez.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca un plan como favorito. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para marcar terminado, reabrir o eliminar. Los lotes grandes se procesan en segundo plano.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar — los últimos registros que viste en cualquier módulo.',
    ],
    // El catalogo plegado de la tarjeta de documentos: el boton solo existe si
    // hay formatos que este plan no usa y quien mira puede armarlo.
    'forms_add'       => 'Añadir formatos (:count)',
    'forms_add_close' => 'Ocultar los formatos disponibles',
];
