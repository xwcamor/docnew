<?php

return [
    // Cabeceras
    'singular'              => 'Mensaje',
    'plural'                => 'Mensajes',
    'inbox'                 => 'Bandeja',
    'new_message'           => 'Nuevo mensaje',
    'edit_message'          => 'Editar mensaje',
    'message_detail'        => 'Detalle del mensaje',
    'unread'                => 'No leído',
    'read'                  => 'Leído',
    'empty_bell'            => 'No tienes mensajes',
    'empty_bell_hint'       => 'Aquí verás avisos, anuncios y debates del administrador.',
    'view_inbox'            => 'Ver bandeja',

    // Campos
    'subject'               => 'Asunto',
    'body'                  => 'Cuerpo',
    'audience'              => 'Audiencia',
    'audience_type'         => 'Tipo de audiencia',
    'audience_target'       => 'Destinatario',
    'audience_global'       => 'Todos los usuarios (Global)',
    'audience_tenant'       => 'Workspace',
    'audience_user'         => 'Usuario',
    'audience_select_tenant'=> 'Seleccionar workspace',
    'audience_select_user'  => 'Seleccionar usuario',
    'allow_replies'         => 'Permitir respuestas / debate',
    'is_active'             => 'Activo',
    'published_at'          => 'Publicado el',
    'expires_at'            => 'Vence el',
    'no_expiration'         => 'Sin vencimiento',
    'created_by'            => 'Creado por',
    'created_at'            => 'Creado el',
    'status_published'      => 'Publicado',
    'status_draft'          => 'Borrador',
    'status_expired'        => 'Vencido',

    // Stats
    'recipients_count'      => 'Destinatarios',
    'read_count'            => 'Leidos',
    'replies_count'         => 'Respuestas',
    'read_pct'              => '% leido',

    // Acciones
    'save_draft'            => 'Guardar borrador',
    'save_and_publish'      => 'Guardar y publicar',
    'publish_now'           => 'Publicar ahora',
    'reply'                 => 'Responder',
    'send_reply'            => 'Enviar respuesta',
    'mark_all_read'         => 'Marcar todo como leido',
    'view_message'          => 'Ver mensaje',

    // Filtros
    'filter_subject'        => 'Buscar por asunto',
    'filter_audience'       => 'Filtrar por audiencia',
    'filter_active'         => 'Estado',
    'only_unread'           => 'No leídos',
    'only_repliable'        => 'Permiten respuesta',
    'tab_all'               => 'Todos',
    'badge_new'             => 'Nuevo',

    // Empty states
    'inbox_empty_title'     => 'Sin mensajes',
    'inbox_empty_hint'      => 'Cuando recibas un anuncio, aparecera aqui.',
    'messages_empty_title'  => 'Sin mensajes creados',
    'messages_empty_hint'   => 'Crea tu primer anuncio para enviarlo a tus usuarios.',
    'replies_empty'         => 'Aun no hay respuestas.',

    // Mensajes flash
    'created_success'       => 'Mensaje creado correctamente.',
    'updated_success'       => 'Mensaje actualizado.',
    'deleted_success'       => 'Mensaje eliminado.',
    'published_success'     => 'Mensaje publicado.',
    'reply_sent'            => 'Respuesta enviada.',
    'mark_all_read_success' => 'Se marcaron :count mensajes como leidos.',

    // Validacion
    'subject_required'           => 'El asunto es obligatorio.',
    'body_required'              => 'El cuerpo es obligatorio.',
    'audience_type_required'     => 'Selecciona la audiencia del mensaje.',
    'audience_id_required'       => 'Selecciona el destinatario.',
    'reply_body_required'        => 'Escribi una respuesta antes de enviar.',
    'reply_body_max'             => 'La respuesta no puede superar 5000 caracteres.',
    'confirm_subject_mismatch'   => 'El asunto ingresado no coincide.',

    // Errores
    'not_a_recipient'      => 'No tienes acceso a este mensaje.',
    'replies_not_allowed'  => 'Este mensaje no permite respuestas.',

    // Confirmacion de baja
    'delete_title'         => 'Eliminar mensaje',
    'delete_warning'       => 'Esta accion soft-elimina el mensaje. Para confirmar, escribe el asunto exacto.',
    'delete_subject_label' => 'Asunto a confirmar',
    'delete_reason_label'  => 'Motivo de la baja',

    // Notificaciones in-app
    'notify_new_reply_title' => 'Nueva respuesta',
    'notify_new_reply_body'  => ':user respondio a "  :subject "',

    // Ayudas (tooltips) y placeholders del formulario
    'subject_help'                  => 'Título corto del mensaje. Aparece en la bandeja del destinatario.',
    'subject_placeholder'           => 'Ej: Cambios importantes en el sistema',
    'body_help'                     => 'Contenido del mensaje. Soporta texto enriquecido.',
    'audience_type_help'            => 'Define el alcance: todos los usuarios, un workspace específico o un usuario individual.',
    'audience_select_tenant_help'   => 'Selecciona el workspace destinatario del mensaje.',
    'audience_select_user_help'     => 'Selecciona el usuario destinatario del mensaje.',
    'allow_replies_help'            => 'Si está activo, los destinatarios pueden responder y abrir un debate.',
    'expires_at_help'               => 'Fecha en que el mensaje deja de mostrarse. Dejar vacío para no vencer.',
    'is_active_help'                => 'Los mensajes inactivos no se muestran en la bandeja.',
];
