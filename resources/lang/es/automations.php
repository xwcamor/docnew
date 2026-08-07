<?php

return [
    // Singular / plural
    'singular' => 'automatización',
    'plural'   => 'Automatizaciones',
    'record'   => 'automatización',
    'records'  => 'automatizaciones',

    // Index
    'index_title'    => 'Automatizaciones',
    'index_subtitle' => 'Reglas que se ejecutan solas según el horario que definas.',
    'new'            => 'Nueva automatización',
    'no_records'     => 'No tienes automatizaciones todavía. Crea una para que el sistema haga el trabajo por ti.',

    // Columns
    'col_name'       => 'Nombre',
    'col_workspace'  => 'Workspace',
    'workspace'             => 'Workspace',
    'workspace_placeholder' => 'Selecciona un workspace…',
    'workspace_hint'        => 'A qué empresa pertenece esta automatización. Determina qué datos consulta y a quién notifica.',
    'workspace_help'        => 'A qué empresa pertenece esta automatización. Determina qué datos consulta y a quién notifica.',
    'col_trigger'    => 'Disparador',
    'col_action'     => 'Acción',
    'col_status'     => 'Estado',
    'col_next_run'   => 'Próxima ejecución',
    'col_last_run'   => 'Última ejecución',
    'col_runs'       => 'Ejecuciones',
    'col_failures'   => 'Fallos',
    'id'             => 'N°',

    // Form sections
    'section_identity' => 'Identidad',
    'section_trigger'  => 'Cuándo ejecutar',
    'section_data'     => 'Qué datos consultar',
    'section_action'   => 'Qué hacer',
    'data_source_not_allowed' => 'No tiene permiso para usar esa fuente de datos. Si está copiando una plantilla, elija otra disponible para su rol.',

    // Helpers de seleccion masiva de destinatarios
    // Placeholders del form
    'name_placeholder'                  => 'Ej: Resumen diario de clientes',
    'name_help'                         => 'Nombre interno para identificar la automatización en el listado. No se muestra al destinatario.',
    'description_placeholder'           => 'Para qué sirve esta automatización (uso interno).',
    'description_help'                  => 'Descripción opcional para tu equipo. Útil para documentar qué hace esta regla y por qué existe.',
    'is_active_help'                    => 'Si está pausada, el sistema la salta en su horario hasta que la vuelvas a activar.',
    'action_email_subject_placeholder'  => 'Ej: Tu resumen diario: {count} clientes nuevos',
    'action_in_app_title_placeholder'   => 'Ej: Tienes {count} clientes pendientes',
    'action_in_app_body_placeholder'    => 'Ej: Tienes {count} pendientes para revisar. Ve al módulo cuando puedas.',

    // Notificación in-app simplificada (sin ack, autoborra a las 12h)
    'notif_success'     => 'Ejecutada correctamente — :count registro(s) encontrados.',
    'notif_failed'      => 'La ejecución falló. Revisa el historial.',
    'notif_email_sent'  => 'Email enviado a :count destinatario(s).',

    // Status compacto para el body del bell ("Modulo · Estado")
    'notif_status_success'    => 'Ejecutada · :count registro(s)',
    'notif_status_failed'     => 'Falló — Ver historial',
    'notif_status_email_sent' => 'Email enviado · :count destinatario(s)',

    // Hint del body in-app explicando que es un recordatorio corto
    'in_app_body_hint'  => 'La campana muestra un recordatorio corto. Si necesitas listar registros, usa la acción Correo.',

    'add_all_admins'   => 'Agregar todos los administradores',
    'add_all_users'    => 'Agregar todos los usuarios',
    'clear_recipients' => 'Limpiar',

    // Preview del mensaje
    'preview_button'              => 'Vista previa del mensaje',
    'preview_title'               => 'Vista previa',
    'preview_hint'                => 'Así se verá el mensaje al ejecutarse. Las variables se sustituyen con datos de ejemplo.',
    'preview_when'                => 'Cuándo se enviará',
    'preview_channel'             => 'Canal',
    'preview_recipients'          => 'Destinatarios',
    'preview_from'                => 'De',
    'preview_from_not_configured' => 'Aún no hay correo remitente configurado en el sistema',
    'preview_subject'             => 'Asunto',
    'preview_empty_subject'       => '(sin asunto — completa el campo)',
    'preview_empty_body'          => '(sin contenido — completa el cuerpo)',
    'preview_no_recipients_email' => '(sin destinatarios — agrega correos)',
    'preview_no_recipients_users' => '(sin destinatarios — selecciona usuarios)',
    'preview_specific_users_count'=> ':n usuario(s) específico(s) del workspace',
    'preview_when_now'            => 'ahora mismo',
    'preview_when_daily'          => 'Todos los días a las :time',
    'preview_when_weekly'         => 'Cada :day a las :time',
    'preview_when_monthly'        => 'El día :day de cada mes a las :time',
    'preview_when_cron'           => 'Programación avanzada (:expr)',
    'preview_legend_title'        => 'Valores de ejemplo usados en esta vista previa',
    'preview_legend_note'         => 'Al ejecutarse de verdad, las variables se reemplazan con los datos reales encontrados por la fuente de datos.',
    'preview_placeholder_name'    => 'Mi automatización',
    'section_identity_help' => 'Nombre y descripción de la automatización. Solo es información interna, los destinatarios no la ven en el mensaje.',
    'section_trigger_help'  => 'Define el horario o frecuencia con la que se ejecuta. Diaria, semanal, mensual o una expresión avanzada para casos específicos.',
    'section_data_help'     => 'Qué módulo consulta y con qué filtros. Los registros encontrados se inyectan en el mensaje vía la variable {list}. Si no eliges fuente, las variables {count} y {list} salen vacías.',
    'section_action_help'   => 'Cómo entrega el mensaje: correo (llega al inbox del destinatario) o aviso (aparece en la campana del header del sistema).',

    // Galería de plantillas (modo CREATE)
    'tpl_gallery_title' => 'Comenzar desde una plantilla',
    'tpl_gallery_hint'  => 'Selecciona un caso de uso común y el formulario se llenará automáticamente. Después puedes ajustar los campos antes de guardar.',
    'tpl_apply'         => 'Usar esta plantilla',
    'tpl_preview_use'   => 'Para qué sirve',
    'tpl_preview_when'  => 'Cuándo se ejecuta',
    'tpl_preview_where' => 'Dónde llega el mensaje',
    'tpl_preview_message' => 'Mensaje que recibirá el destinatario',
    'tpl_preview_after_apply_hint' => 'Al aplicar la plantilla todos los campos quedarán pre-llenos. Asegúrate de completar los destinatarios y revisar el contenido antes de guardar.',

    // Plantilla 1: Resumen diario de clientes
    'tpl_daily_customers_title'   => 'Resumen diario de clientes',
    'tpl_daily_customers_use'     => 'Recibe cada mañana por email la lista completa de clientes activos de tu workspace.',
    'tpl_daily_customers_when'    => 'Todos los días a las 09:00',
    'tpl_daily_customers_where'   => 'Email al destinatario configurado',
    'tpl_daily_customers_subject' => 'Tu resumen diario: {count} clientes activos',
    'tpl_daily_customers_body'    => "Hola,\n\nEste es el resumen de tu workspace al {date}.\n\nClientes activos ({count}):\n{list}\n\nGenerado por: {automation}",

    // Plantilla 2: Alerta de inactivos
    'tpl_inactive_title'   => 'Alerta semanal de clientes inactivos',
    'tpl_inactive_use'     => 'Cada lunes te avisa por la campana cuántos clientes están marcados como inactivos para que tu equipo los revise.',
    'tpl_inactive_when'    => 'Cada lunes a las 08:00',
    'tpl_inactive_where'   => 'Aviso para los administradores del workspace',
    'tpl_inactive_subject' => 'Hay {count} clientes inactivos para revisar',
    'tpl_inactive_body'    => 'Recordatorio: hay {count} clientes inactivos en tu workspace. Revísalos para reactivar o eliminar definitivamente.',

    // Plantilla 3: Reporte semanal del equipo
    'tpl_team_title'   => 'Reporte semanal del equipo',
    'tpl_team_use'     => 'Cada lunes recibes por email un resumen de los usuarios activos en tu workspace y quiénes son.',
    'tpl_team_when'    => 'Cada lunes a las 09:00',
    'tpl_team_where'   => 'Email al destinatario configurado',
    'tpl_team_subject' => 'Reporte semanal: {count} usuarios activos en tu equipo',
    'tpl_team_body'    => "Hola,\n\nEste es el estado del equipo al {date}.\n\nUsuarios activos ({count}):\n{list}\n\nGenerado por: {automation}",

    // Plantilla 4: Suscripciones activas (super)
    'tpl_subs_title'   => 'Estado mensual de suscripciones',
    'tpl_subs_use'     => 'El primer día de cada mes recibes por email la lista de workspaces con suscripción activa, útil para super-admins.',
    'tpl_subs_when'    => 'El día 1 de cada mes a las 08:00',
    'tpl_subs_where'   => 'Email al destinatario configurado',
    'tpl_subs_subject' => 'Suscripciones activas este mes: {count}',
    'tpl_subs_body'    => "Hola,\n\nAl {date} hay {count} workspaces con suscripción activa:\n{list}\n\nGenerado por: {automation}",

    // Plantilla 5: Transformadores por cliente
    'tpl_tr_cust_title'   => 'Por transformador y cliente',
    'tpl_tr_cust_use'     => 'Cada lunes recibes un aviso con los transformadores de un cliente. Elige el cliente al guardar.',
    'tpl_tr_cust_when'    => 'Cada lunes a las 08:00',
    'tpl_tr_cust_where'   => 'Aviso para los administradores del workspace',
    'tpl_tr_cust_subject' => 'Transformadores del cliente: {count}',
    'tpl_tr_cust_body'    => "Al {date}, el cliente seleccionado tiene {count} transformadores registrados.\n\nGenerado por: {automation}",

    // Plantilla 6: Transformadores por cliente y estado
    'tpl_tr_state_title'   => 'Por transformador, cliente y estado',
    'tpl_tr_state_use'     => 'Como la anterior, pero solo los transformadores del cliente en mal estado de salud (Malo o Crítico). Ajusta el cliente y el estado al guardar.',
    'tpl_tr_state_when'    => 'Cada lunes a las 08:00',
    'tpl_tr_state_where'   => 'Aviso para los administradores del workspace',
    'tpl_tr_state_subject' => 'Transformadores del cliente en mal estado: {count}',
    'tpl_tr_state_body'    => "Al {date}, el cliente seleccionado tiene {count} transformadores en mal estado de salud (Malo o Crítico).\n\nGenerado por: {automation}",

    // Plantilla 7: Transformadores por cliente, estado e índice
    'tpl_tr_idx_title'   => 'Por transformador, cliente, estado e índice',
    'tpl_tr_idx_use'     => 'Transformadores del cliente en mal estado de salud y con índice bajo (menor a 50): los que requieren atención. Ajusta cliente, estado e índice al guardar.',
    'tpl_tr_idx_when'    => 'Cada lunes a las 08:00',
    'tpl_tr_idx_where'   => 'Aviso para los administradores del workspace',
    'tpl_tr_idx_subject' => 'Transformadores del cliente que requieren atención: {count}',
    'tpl_tr_idx_body'    => "Al {date}, el cliente seleccionado tiene {count} transformadores en mal estado de salud e índice menor a 50. Revísalos cuanto antes.\n\nGenerado por: {automation}",

    // Fields
    'name'        => 'Nombre',
    'description' => 'Descripción',
    'is_active'   => 'Activa',
    'is_active_hint' => 'Si está pausada, el sistema la salta en su horario hasta que la vuelvas a activar.',

    // Trigger
    'trigger_kind'         => 'Frecuencia',
    'trigger_kind_help'    => 'Con qué frecuencia se ejecuta. Elige diaria, semanal, mensual o una expresión cron avanzada.',
    'trigger_kind_placeholder' => 'Selecciona la frecuencia…',
    'trigger_kind_daily'   => 'Cada día',
    'trigger_kind_weekly'  => 'Cada semana',
    'trigger_kind_monthly' => 'Cada mes',
    'trigger_kind_cron'    => 'Expresión cron (avanzado)',
    'trigger_time'         => 'Hora',
    'trigger_time_help'    => 'Hora exacta del día en que se ejecuta, en la zona horaria del workspace.',
    'trigger_time_placeholder' => 'Ej: 09:00',
    'trigger_day_of_week'  => 'Día de la semana',
    'trigger_day_of_week_help' => 'Día de la semana en el que se dispara la ejecución semanal.',
    'trigger_day_of_week_placeholder' => 'Selecciona un día…',
    'trigger_day_of_month' => 'Día del mes',
    'trigger_day_of_month_help' => 'Día del mes en el que se dispara la ejecución mensual (1 a 31).',
    'trigger_day_of_month_placeholder' => 'Ej: 1',
    'trigger_cron_expression' => 'Expresión cron',
    'trigger_cron_expression_help' => 'Formato estándar de cinco campos: minuto hora día mes día-semana. Ej: 0 9 * * 1.',
    'trigger_cron_hint'    => 'Formato estándar: minuto hora día mes día-semana. Ej: 0 9 * * 1',

    // Data source
    'data_source'       => 'Fuente de datos',
    'data_source_help'  => 'Módulo que se consulta antes de ejecutar la acción. Sus registros se inyectan en las variables {count} y {list}.',
    'data_source_none'  => 'Sin datos previos (solo enviar mensaje)',
    'data_filter_intro' => 'Filtros — solo se incluyen registros que cumplan TODAS las condiciones.',
    'data_filter_add'   => 'Agregar condición',
    'data_filter_limit' => 'Máximo de registros',
    'data_filter_limit_help' => 'Tope de filas a procesar en cada ejecución. Útil para evitar correos demasiado largos.',
    'data_filter_limit_placeholder' => 'Ej: 100',

    // Source labels (usados por DataSourceContract::label())
    'source_customers'     => 'Clientes',
    'source_transformers'  => 'Transformadores',
    'source_users'         => 'Usuarios',
    'source_subscriptions' => 'Suscripciones',

    // Campos propios de algún data source
    'field_health_rating'  => 'Estado de salud (0 crítico … 4 excelente)',

    // Action
    'action_type'                => 'Tipo de acción',
    'action_type_help'           => 'Cómo entrega el mensaje: por correo al inbox del destinatario o como aviso en la campana del header.',
    'action_type_placeholder'    => 'Selecciona el tipo de acción…',
    'action_email'               => 'Correo',
    'action_email_to'            => 'Destinatarios',
    'action_email_to_help'       => 'Elige usuarios del workspace o escribe correos externos. Confirma cada uno con Enter o coma.',
    'action_email_to_hint'       => 'Elige usuarios del workspace o escribe correos externos. Confirma con Enter o coma.',
    'action_email_to_placeholder'=> 'Selecciona usuarios o escribe correos…',
    'action_email_subject'       => 'Asunto',
    'action_email_subject_help'  => 'Asunto del correo. Puedes usar variables como {count} o {date}.',
    'action_email_body'          => 'Cuerpo del mensaje',
    'action_email_body_help'     => 'Contenido del correo. Inserta variables ({count}, {list}, {date}) desde el panel lateral.',
    'action_email_body_placeholder' => 'Hola, hay {count} registros pendientes:'."\n".'{list}',
    'action_in_app'              => 'Aviso',
    'action_in_app_recipients'   => 'Destinatarios',
    'action_in_app_recipients_help' => 'Elige si llega a todos los administradores del workspace o solo a usuarios específicos.',
    'action_in_app_recipients_placeholder' => 'Selecciona destinatarios…',
    'action_in_app_recipients_admins' => 'Todos los administradores del workspace',
    'action_in_app_recipients_specific'=> 'Usuarios específicos',
    'action_in_app_user_ids'     => 'Usuarios',
    'action_in_app_specific_users' => 'Usuarios destinatarios',
    'action_in_app_specific_users_help' => 'Solo estos usuarios recibirán la notificación.',
    'action_in_app_specific_users_hint' => 'Solo estos usuarios recibirán la notificación.',
    'action_in_app_specific_users_placeholder' => 'Selecciona uno o más usuarios…',
    'action_in_app_title'        => 'Título',
    'action_in_app_title_help'   => 'Título corto del aviso que aparece en la campana del header.',
    'action_in_app_body'         => 'Mensaje',
    'action_in_app_body_help'    => 'Texto corto del aviso. Para listas extensas usa la acción Correo en su lugar.',

    // Variables disponibles en plantillas (chips clickeables)
    // Operadores de filtros
    'op_contains' => 'contiene',
    'op_in'       => 'en lista',
    'value_in_placeholder' => 'Valores separados por coma',

    'template_variables'         => 'Variables disponibles: {count}, {list}, {date}, {automation}',
    'template_variables_label'   => 'Variables disponibles (clic para insertar)',
    'var_count_label'            => 'Cantidad',
    'var_count_desc'             => 'Cantidad de registros encontrados por el data source (0 si no hay).',
    'var_list_label'             => 'Lista',
    'var_list_desc'              => 'Lista con guiones de los registros encontrados.',
    'var_date_label'             => 'Fecha actual',
    'var_date_desc'              => 'Fecha del momento en que se ejecuta la automatización (AAAA-MM-DD).',
    'var_automation_label'       => 'Nombre',
    'var_automation_desc'        => 'Nombre de la automatización que disparó el mensaje.',
    'var_automation_example'     => 'Reporte semanal de clientes',

    // Acciones
    'run_now'         => 'Ejecutar ahora',
    'run_now_hint'    => 'Disparar la automatización inmediatamente (test)',
    'queued_for_run'  => 'Encolada para ejecutarse ahora.',
    'run_done'        => 'Automatización ejecutada: :summary',
    'run_failed'      => 'La automatización falló: :error',
    'toggle_active'   => 'Pausar / Reanudar',
    'edit_hint'       => 'Modificar trigger, datos o acción',
    'delete_hint'     => 'Eliminar (queda en papelera)',

    // Tabs en Show
    'tab_general'  => 'Configuración',
    'tab_runs'     => 'Historial de ejecuciones',
    'tab_history'  => 'Historial',

    // Run status
    'run_running' => 'En curso',
    'run_success' => 'Exitoso',
    'run_failed'  => 'Falló',

    'no_runs'        => 'Todavía no se ejecutó. La próxima ejecución será el {date}.',
    'next_run_none'  => 'Sin programación válida — revisa el trigger.',

    // Plan gating
    'feature_required'      => 'Las automatizaciones requieren el plan Enterprise.',
    'feature_required_hint' => 'Actualiza tu plan para crear reglas automáticas.',

    // Mensajes flash
    'created' => 'Automatización creada.',
    'saved'   => 'Automatización actualizada.',
    'deleted' => 'Automatización eliminada.',

    // Validación delete
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Email
    'email_footer' => 'Este correo fue generado automáticamente por una automatización del sistema.',

    // Bulk + validación adicional
    'is_active_required' => 'Indica si activar o pausar las automatizaciones seleccionadas.',
    'name_duplicate_in_batch' => 'Hay nombres repetidos en el lote — cada automatización debe tener un nombre único.',

    // Edit-All
    'edit_all_title'    => 'Editar muchas automatizaciones',
    'edit_all_subtitle' => 'Cambia nombre y estado de varias a la vez. Pulsa "Guardar todo" para confirmar.',
    'edit_all_discard'  => 'Descartar cambios',
    'edit_all_save_all' => 'Guardar todo',
    'edit_all_changes'  => 'Tienes {count} cambio(s) pendientes.',
    'edit_all_no_results' => 'No hay automatizaciones para editar con estos filtros.',
    'table_headers' => [
        'editable_name'   => 'Nombre',
        'editable_status' => 'Estado',
    ],

    // Export / Import
    'export_filename'          => 'exportacion_automatizaciones',
    'export_title'             => 'Listado de automatizaciones',
    'export_limit_exceeded'    => 'El export en :format excede el límite (:count filas vs :limit máximo). Usa CSV para datasets grandes (sin límite).',
    'export_format_limit_hint' => 'Máximo :limit filas para este formato. Usa CSV para datasets grandes.',
    'export_no_limit_hint'     => 'Sin límite — recomendado para datasets grandes.',
    'import_template_filename' => 'plantilla-automatizaciones.xlsx',

    // Import-specific errors (extienden imports.err_* con casos propios)
    'err_trigger_kind_invalid'      => 'La columna trigger_kind debe ser cron, daily, weekly o monthly.',
    'err_cron_expression_required'  => 'Cuando trigger_kind=cron, la columna trigger_expression es obligatoria.',
    'err_data_source_required'      => 'La columna data_source es obligatoria.',
    'err_action_type_required'      => 'La columna action_type es obligatoria.',
    'err_action_config_required'    => 'La columna action_config_json es obligatoria.',
    'err_action_config_invalid_json' => 'La columna action_config_json no es un JSON válido (debe ser un objeto).',

    // Onboarding tour — claves consumidas por config/tour.js. Texto corto y útil.
    'tour' => [
        'step1_title' => 'Bienvenido a Automatizaciones',
        'step1_body'  => 'Las automatizaciones ejecutan acciones por ti según el horario que definas. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca por nombre, estado, fuente de datos o tipo de acción. Los filtros activos aparecen como chips arriba de la tabla.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra u oculta columnas y recordamos tu elección. Las marcadas como obligatorias no se pueden esconder.',
        'step5_title' => 'Editar muchas a la vez',
        'step5_body'  => '"Editar todo" permite modificar nombre y estado de varias automatizaciones juntas, en un único guardado.',
        'step6_title' => 'Favoritos',
        'step6_body'  => 'La estrella marca una automatización como favorita. Los favoritos aparecen siempre arriba del listado.',
        'step7_title' => 'Operaciones masivas',
        'step7_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, pausar o eliminar varias a la vez.',
        'step8_title' => 'Papelera',
        'step8_body'  => 'Abre la papelera para ver automatizaciones eliminadas (solo super). Desde ahí puedes restaurarlas o borrarlas definitivamente.',
        'step9_title' => 'Auditoría',
        'step9_body'  => 'Historial completo de cambios: quién, qué y cuándo. Útil para investigar errores o problemas de configuración.',
        'step10_title' => 'Crear nueva',
        'step10_body'  => 'Botón principal para crear una nueva automatización. Atajo de teclado: Ctrl+N.',
        'step11_title' => '¿Necesitas un repaso?',
        'step11_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba.',
    ],
];
