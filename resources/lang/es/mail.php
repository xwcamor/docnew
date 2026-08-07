<?php

return [
    // ── DownloadReady ─────────────────────────────────────────────────
    'download_ready_intro'     => 'El export de tipo :type que solicitaste ya está listo para descargar.',
    'download_ready_filename'  => 'Archivo: :filename',
    'download_ready_expires'   => 'El enlace expira el :date. Después de esa fecha tendrás que generar el reporte de nuevo.',
    'download_ready_footer'    => 'Si no reconoces esta acción, puedes ignorar este mensaje.',

    // ── DownloadFailed ────────────────────────────────────────────────
    'download_failed_intro'    => 'No se pudo completar el export de tipo :type que solicitaste.',
    'download_failed_filename' => 'Archivo solicitado: :filename',
    'download_failed_reason'   => 'Motivo: :reason',
    'download_failed_retry'    => 'Intenta nuevamente desde la pantalla del módulo. Si el problema persiste, contacta soporte.',

    // ── PlanChanged ──────────────────────────────────────────────────
    'plan_changed_subject'        => 'Tu plan cambió: :workspace ahora es :plan',
    'plan_changed_intro'          => 'El plan de tu workspace cambió de :previous a :current.',
    'plan_upgraded_intro'         => 'Tu workspace subió de :previous a :current. Ya tienes acceso a las nuevas features incluidas en el plan.',
    'plan_downgraded_intro'       => 'Tu workspace bajó de :previous a :current. Algunas features pueden dejar de estar disponibles.',
    'plan_downgraded_apis'        => 'Si tenías API keys generadas, siguen guardadas pero quedan en pausa hasta que vuelvas a un plan con la feature de API. Las peticiones recibirán código 402.',
    'plan_downgraded_upgrade_hint'=> 'Desde el módulo de tu workspace puedes subir el plan en cualquier momento para reactivar todo.',
    'plan_changed_footer'         => 'Si no esperabas este cambio, contacta al administrador de la plataforma.',

    // ── Welcome (creación de cuenta) ─────────────────────────────────
    'welcome_subject'              => 'Bienvenido a :app',
    'welcome_greeting'             => 'Hola :name,',
    'welcome_intro'                => 'Se creó una cuenta para ti en :app. A continuación tienes tus credenciales para acceder por primera vez.',
    'welcome_credentials_intro'    => 'Tus datos de acceso son:',
    'welcome_email_label'          => 'Correo',
    'welcome_password_label'       => 'Contraseña',
    'welcome_button'               => 'Ingresar al sistema',
    'welcome_change_password_hint' => 'Por seguridad, cambia tu contraseña desde tu perfil apenas inicies sesión.',
    'welcome_salutation'           => 'Bienvenido al equipo, :app',

    // ── PasswordChanged (aviso de seguridad) ─────────────────────────
    'password_changed_subject'        => 'Tu contraseña fue actualizada — :app',
    'password_changed_greeting'       => 'Hola :name,',
    'password_changed_intro_self'     => 'Te confirmamos que cambiaste tu contraseña correctamente.',
    'password_changed_intro_admin'    => 'Un administrador del sistema cambió tu contraseña. Si necesitas la nueva clave, contáctalo.',
    'password_changed_intro_reset'    => 'Completaste el proceso de "olvidé mi contraseña" y tu clave fue restablecida.',
    'password_changed_when'           => 'Fecha y hora del cambio: :datetime.',
    'password_changed_security_warning'=> 'Si tú NO realizaste este cambio, alguien más pudo haber accedido a tu cuenta. Restablece tu contraseña inmediatamente usando el botón de abajo.',
    'password_changed_button'         => 'Restablecer mi contraseña',
    'password_changed_salutation'     => 'Saludos, equipo de :app',

    // Solicitud de eliminación de cuenta (derecho de cancelación — LPDP)
    'deletion_request_subject'  => ':app — Solicitud de eliminación de cuenta',
    'deletion_request_greeting' => 'Hola :name,',
    'deletion_request_body'     => ':name (:email) solicitó la eliminación de su cuenta y datos personales.',
    'deletion_request_hint'     => 'Procesa la solicitud desde el módulo de Usuarios (eliminar al usuario). La solicitud quedó registrada en el log de auditoría.',
    'deletion_request_button'   => 'Ir a Usuarios',
];
