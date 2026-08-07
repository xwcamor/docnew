<?php

return [
    // Page
    'title'              => 'Mi perfil',
    'subtitle'           => 'Administra tu información personal y configuración de cuenta.',

    // Tabs
    'tab_info'           => 'Información',
    'tab_password'       => 'Contraseña',
    'tab_preferences'    => 'Preferencias',

    // Info section
    'name'               => 'Nombre',
    'email'              => 'Correo electrónico',
    'email_readonly_hint' => 'El correo identifica tu cuenta y no se puede modificar desde aquí. Contacta al administrador si necesitas cambiarlo.',
    'tenant'             => 'Organización',
    'country'            => 'País',
    'timezone'           => 'Zona horaria',
    'timezone_hint'      => 'Deja la opción "Usar la del workspace" para heredar la zona del workspace o tu país.',
    'timezone_inherit'   => 'Usar la del workspace',
    'roles'              => 'Roles',
    'member_since'       => 'Miembro desde',
    'save_info'          => 'Guardar información',

    // Password section
    'password_title'     => 'Cambiar contraseña',
    'password_subtitle'  => 'Una contraseña fuerte tiene al menos 8 caracteres, letras y números.',
    'current_password'   => 'Contraseña actual',
    'new_password'       => 'Nueva contraseña',
    'confirm_password'   => 'Confirmar nueva contraseña',
    'change_password'    => 'Cambiar contraseña',
    'no_password_hint'   => 'Aún no tienes una contraseña local (iniciaste con Google). Define una para poder iniciar sesión también con email.',
    'password_updated'   => 'Contraseña actualizada correctamente.',
    'current_password_incorrect' => 'La contraseña actual no es correcta.',

    // Firma manuscrita (imagen privada; se estampa en los informes que el
    // propio usuario genera, como "Preparado por").
    'signature_title'   => 'Mi firma',
    'signature_hint'    => 'Imagen de tu firma manuscrita (PNG/JPG, fondo blanco o transparente, máx. 1 MB). Se estampa automáticamente en los informes PDF que TÚ generas, en la línea "Preparado por". Nadie más puede usarla: es privada y solo se aplica con tu acción.',
    'signature_upload'  => 'Subir firma',
    'signature_change'  => 'Cambiar firma',
    'signature_remove'  => 'Quitar',
    'signature_updated' => 'Firma actualizada.',
    'signature_removed' => 'Firma eliminada.',
    'signature_draw'       => 'Dibujar firma',
    'signature_draw_hint'  => 'Dibuja tu firma con el mouse o el dedo. Puedes limpiar y volver a intentar las veces que quieras.',
    'signature_draw_clear' => 'Limpiar',
    'signature_draw_save'  => 'Guardar firma',

    // Auto-firma como aprobador (consentimiento propio, auditado, revocable)
    'autosign_label' => 'Auto-firmar informes como aprobador',
    'autosign_hint'  => 'Si estás asignado como aprobador del workspace, tu firma se estampará automáticamente en los informes SIN aprobación individual. Es tu autorización: queda registrada y puedes revocarla cuando quieras.',
    'autosign_needs_signature' => 'Primero carga tu firma para poder activar la auto-firma.',

    // Privacidad y datos (derechos ARCO — LPDP Ley 29733)
    'privacy_title'      => 'Privacidad y datos',
    'privacy_hint'       => 'Tus derechos sobre tus datos personales (acceso, rectificación, cancelación, oposición).',
    'download_my_data'   => 'Descargar mis datos',
    'request_deletion'   => 'Solicitar eliminación de mi cuenta',
    'request_deletion_confirm' => 'Se registrará tu solicitud y se notificará al administrador del workspace, quien procesará la eliminación. Esta acción no borra tu cuenta de inmediato. ¿Continuar?',
    'request_deletion_ok'      => 'Sí, solicitar',
    'deletion_requested'       => 'Solicitud registrada. El administrador fue notificado.',
    'deletion_already_requested' => 'Tu solicitud ya está registrada y el administrador fue notificado. No hace falta volver a enviarla.',
    'privacy_rights_note'      => 'Para rectificar tus datos, edita tu perfil. Para otras consultas sobre tus datos personales, contacta al administrador de tu workspace.',

    // Foto de perfil (clic en el avatar)
    'photo_change'  => 'Cambiar foto de perfil',
    'photo_updated' => 'Foto de perfil actualizada.',

    // Preferences section
    'preferences_title'  => 'Preferencias de la app',
    'preferences_hint'   => 'Estas preferencias también se pueden cambiar desde la barra superior.',
    'appearance_title'   => 'Apariencia',
    'appearance_hint'    => 'Personaliza los colores y la posición del menú. Se guarda en tu cuenta y te sigue en cualquier dispositivo.',
    'color_scheme'       => 'Esquema de color',
    'scheme_contrast'    => 'Alto contraste',
    'menu_position'      => 'Diseño del menú',
    'menu_top'           => 'Pantalla completa',
    'menu_side'          => 'Con sidebar',
    'menu_bottom'        => 'Abajo',
    'menu_position_hint' => 'Con sidebar: menú fijo a la izquierda. Pantalla completa: el menú se abre con la hamburguesa (☰) y el contenido usa todo el ancho. Aplica solo en pantalla grande; en móvil el menú sale siempre desde el botón superior.',
    'preferred_language' => 'Idioma preferido',
    'preferred_theme'    => 'Tema',
    'tour_status'        => 'Tours de onboarding',
    'tours_completed'    => ':count tour completado|{1} 1 tour completado|[2,*] :count tours completados',
    'reset_tours'        => 'Reiniciar tours',
    'reset_tours_done'   => 'Tours reiniciados. Los verá otra vez la próxima vez que entre a cada módulo.',
];
