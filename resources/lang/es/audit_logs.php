<?php

return [
    // Header
    'title'           => 'Auditoría',
    'events_singular' => 'evento registrado',
    'events_plural'   => 'eventos registrados',
    'visible_for'     => 'Visible para super y admin',
    'clear_filters'   => 'Limpiar filtros',
    // Un valor cifrado que no abre con la clave de hoy. Es el síntoma de un
    // APP_KEY rotado sin re-cifrar, y en un historial eso es información: se
    // dice, no se inventa un valor ni se revienta la pantalla.
    'unreadable'      => '(no se puede leer con la clave actual)',

    // Filters
    'filter_module'    => 'Módulo',
    'filter_event'     => 'Evento',
    'filter_user_id'   => 'ID de usuario',
    'filter_user'      => 'Usuario',
    'filter_record_id' => 'ID de registro',
    'filter_from'      => 'Desde',
    'filter_to'        => 'Hasta',

    // Table columns
    'col_date'   => 'Fecha',
    'col_event'  => 'Evento',
    'col_module' => 'Módulo',
    'col_record' => 'Registro #',
    'col_user'   => 'Usuario',
    'col_url'    => 'URL del registro',

    // Event labels
    'event_created'       => 'Creado',
    'event_updated'       => 'Modificado',
    'event_deleted'       => 'Eliminado',
    'event_force_deleted' => 'Borrado permanente',
    'event_restored'      => 'Restaurado',
    'event_login'         => 'Inicio de sesión',
    'event_logout'        => 'Cierre de sesión',
    // De un intento fallido se guarda el correo con el que se probó, nunca la
    // contraseña. Varios seguidos desde la misma IP es lo que hay que poder ver.
    'event_login_failed'  => 'Intento fallido',
    'event_login_lockout' => 'Cuenta frenada por intentos',
    // Contraseña correcta, cuenta dada de baja: no llegó a entrar.
    'event_login_blocked' => 'Acceso rechazado (cuenta desactivada)',
    'event_purged'        => 'Purgado',
    'event_export_queued' => 'Exportación solicitada',
    'event_terms_accepted' => 'Términos aceptados',
    'event_personal_data_exported'     => 'Datos personales descargados',
    'event_account_deletion_requested' => 'Baja de cuenta solicitada',
    'event_report_autosign_granted'    => 'Firma automática activada',
    'event_report_autosign_revoked'    => 'Firma automática desactivada',

    // Cells
    'system'       => 'Sistema',
    'go_to_record' => 'Ir al registro',
    'view_detail'  => 'Ver detalle',

    // Empty state
    'empty_title' => 'Sin eventos registrados',
    'empty_desc'  => 'Aún no hay actividad auditada con los filtros aplicados.',

    // Drawer detail
    'drawer_title'    => 'Detalle del evento',
    'detail_id'       => 'ID',
    'detail_date'     => 'Fecha',
    'detail_event'    => 'Evento',
    'detail_module'   => 'Módulo',
    'detail_model'    => 'Modelo',
    'detail_record_id' => 'Registro ID',
    'detail_user'     => 'Usuario',
    'detail_url'      => 'URL del registro',
    'detail_ip'       => 'IP',
    'detail_ua'       => 'User-Agent',
    'old_values'      => 'Valores anteriores',
    'new_values'      => 'Valores nuevos',
];
