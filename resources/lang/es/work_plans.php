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
    'code_help'            => 'Lo genera el sistema al guardar: país, año, día del trabajo y el número que le toca ese día. No se repite nunca.',
    'code_auto'            => 'Se asigna solo al guardar — el correlativo del día del trabajo.',
    'num_os'               => 'Orden de servicio',
    'num_os_help'          => 'Número de la orden de servicio del cliente, si la hay.',
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
    'crew_add'      => 'Añadir trabajador',
    'crew_add_title'=> 'Añadir trabajador',
    // La misma frase que en obra: se escanea el DNI, no se busca por apellido.
    'crew_search_placeholder' => 'Escanea o escribe el documento del trabajador…',
    'crew_search_hint'        => 'Escribe el documento completo (8 dígitos).',
    'crew_no_results'         => 'Ese documento no está registrado. Da de alta al trabajador primero.',
    'crew_remove'   => 'Quitar del plan',
    'crew_remove_confirm' => '¿Quitar a :name de este plan?',
    // Si la persona tiene la cara registrada se ve en el módulo de Trabajadores,
    // que es donde se registra. En la ficha del plan no pinta nada: ahí lo que
    // importa es si firmó y cuándo.
    'crew_enrolled'     => 'Cara registrada',
    'crew_not_enrolled' => 'Sin cara registrada',
    'crew_signed'       => 'Firmó',
    'crew_signed_at'    => 'Firmado el :when',
    'crew_pending'      => 'Falta su firma',
    'crew_sign_hint'    => 'Firmar por :name con reconocimiento facial',
    'crew_added'    => ':name se añadió al plan.',
    'crew_removed'  => 'El trabajador se quitó del plan.',
    'crew_already_assigned'     => ':name ya está en este plan.',
    'crew_signed_cannot_remove' => 'No se puede quitar a :name: ya firmó este plan. Su firma es la prueba de que estuvo en la obra y recibió la charla, así que el registro se conserva.',

    'forms_title'    => 'Formatos de seguridad',
    'forms_not_in_plan'  => 'No se exige en este plan',
    'forms_toggle_hint'  => 'Exigir o no el formato :code en este plan',
    'forms_open_hint'    => 'Abrir el formato :code para llenarlo',
    'forms_pdf_hint'     => 'Descargar el :code en PDF',
    'forms_subtitle' => 'Los pone el tipo de trabajo. Aquí se suma o se quita uno.',
    'forms_summary'  => '{0} Ninguno lleno|[1,*] :done de :total llenos',
    'forms_empty'    => 'El tipo de trabajo de este plan no exige ningún formato.',
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
    // Por qué una aprobación todavía no se puede firmar. El sistema anterior
    // directamente las escondía; aquí se enseñan en gris con el motivo, que es
    // mejor: se ve el camino completo sin poder saltárselo.
    // Lo que le falta al plan para cerrarse solo. Las dos condiciones del
    // sistema anterior, ni una más.
    'close_needs_date_end'  => 'Falta la hora de fin del trabajo.',
    // Literalmente los mensajes de la v1: «Debe tener al menos 1 trabajador» /
    // «...1 documento». Allí impedían guardar; aquí impiden cerrar, porque el
    // plan se arma después de crearse.
    'close_needs_crew'      => 'Debe tener al menos 1 trabajador.',
    'close_needs_forms'     => 'Debe tener al menos 1 formato.',
    'close_needs_signatures'  => '{1} Falta la firma de 1 trabajador.|[2,*] Faltan las firmas de :count trabajadores.',
    'close_needs_forms_done'  => '{1} Falta confirmar 1 formato.|[2,*] Faltan :count formatos por confirmar.',
    'close_needs_approvals' => '{1} Falta 1 aprobación obligatoria.|[2,*] Faltan :count aprobaciones obligatorias.',

    // La regla de la v1: hasta que el ejecutante no firma su aprobación, las
    // demás ni se enseñaban. No es «que firme la cuadrilla» — eso son firmas de
    // asistencia y no gobiernan la autorización.
    'approval_waits_worker' => 'Primero tiene que firmar :role.',
    'approval_waits_prior' => 'Espera la firma de :role.',

    'approver_role' => [
        'worker'         => 'Trabajador',
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
];
