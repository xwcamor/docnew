<?php

return [
    // Login
    'rememberme'       => 'Recuérdame',
    'forgot_password'  => '¿Olvidaste tu contraseña?',
    'login'            => 'Iniciar sesión',
    'back_to_login'    => 'Volver al inicio de sesión',
    'login_google'     => 'Inciar con cuenta de Google',
    'disclaimer_affiliation'  => ':app no está afiliado ni respalda a ninguna empresa cuyos nombres o logos aparezcan cargados por usuarios.',
    'client_content_responsibility' => 'El contenido cargado por cada cliente es de su exclusiva responsabilidad.',
    'all_rights_reserved'     => 'Todos los derechos reservados.',
    'start_session'    => 'Sesión iniciada',
    'end_session'      => 'Sesión finalizada',
    'error_session'    => 'Usuario ó contraseña incorrecto',
    'locked'           => 'Demasiados intentos fallidos. Tu cuenta está bloqueada temporalmente. Intenta nuevamente en :minutes minutos.',

    // Email de "olvidé contraseña"
    'password_reset_subject'    => 'Restablece tu contraseña — :app',
    'password_reset_greeting'   => '¡Hola!',
    'password_reset_line_intro' => 'Recibes este email porque solicitamos un restablecimiento de contraseña para tu cuenta.',
    'password_reset_button'     => 'Restablecer contraseña',
    'password_reset_line_expire'=> 'Este enlace expirará en :count minutos.',
    'password_reset_line_ignore'=> 'Si tú no solicitaste este cambio, ignora este mensaje. Tu contraseña seguirá siendo la misma.',
    'password_reset_salutation' => 'Saludos, equipo de :app',
    'login_error'      => 'Algo salió mal o has rechazado la aplicación.',
    'account_inactive' => 'Tu cuenta no está activa. Contacta al administrador.',
    'account_created_needs_activation' => 'Tu cuenta ha sido creada, pero necesita ser activada.',

    // Forgot Password
    'request_title'         => 'Recuperar contraseña',
    'request_message'       => 'Escribe tu correo y te enviamos un enlace para poner una contraseña nueva.',
    'send_reset_link'       => 'Enviar enlace',
    'reset_title'           => 'Poner una contraseña nueva',
    'reset_message'         => 'Elige la contraseña con la que vas a entrar a partir de ahora.',
    'reset_password_button' => 'Guardar contraseña',

    // Recuperar contraseña — pantalla completa
    'forgot_tagline'        => '¿No puedes entrar?',
    'forgot_back'           => 'Volver',
    'forgot_point_link'     => 'Te llega un enlace a tu correo de trabajo',
    'forgot_point_minutes'  => 'Se hace en un minuto, desde el móvil',
    'forgot_point_nosupport'=> 'No hace falta llamar a nadie',
    'forgot_remembered'     => '¿Ya te acordaste?',
    'forgot_sent_title'     => 'Mira tu correo',
    'forgot_sent_body'      => 'Si :email está registrado, ahí tienes el enlace para poner tu contraseña.',
    'forgot_notfound_title' => '¿No te llega?',
    'forgot_tip_wait'       => 'Puede tardar uno o dos minutos.',
    'forgot_tip_spam'       => 'Mira la carpeta de correo no deseado.',
    'forgot_tip_typo'       => 'Comprueba que el correo esté bien escrito.',
    'forgot_resend'         => 'Enviar otra vez',
    'forgot_other_email'    => 'Usar otro correo',

    // Nueva contraseña — pantalla completa
    'reset_tagline'         => 'Elige tu contraseña',
    'reset_new_password'    => 'Contraseña nueva',
    'reset_repeat_password' => 'Repite la contraseña',
    'reset_min_placeholder' => 'Mínimo 8 caracteres',
    'reset_repeat_placeholder' => 'Escríbela otra vez',
    'reset_rule_length'     => 'Mínimo 8 caracteres',
    'reset_rule_mix'        => 'Que lleve letras y números',
    'reset_rule_instant'    => 'Empieza a valer al momento',

    // Primera vez: los usuarios que vienen del sistema anterior no tienen
    // contraseña propia, así que su ÚNICA entrada es «olvidé mi contraseña».
    // Si esto no se dice en la pantalla de acceso, se quedan fuera.
    'first_time_title' => '¿Es tu primera vez?',
    'first_time_body'  => 'Si tu cuenta viene del sistema anterior, todavía no tienes contraseña. Pulsa «¿Olvidaste tu contraseña?» y crea la tuya con el enlace que te llegue.',

    // Token Reset
    'reset'     => '¡Tu contraseña ha sido restablecida!',
    'sent'      => '¡Hemos enviado por correo el enlace para restablecer tu contraseña!',
    'throttled' => 'Por favor espera antes de volver a intentarlo.',
    'token'     => 'Este token de restablecimiento de contraseña no es válido.',
    'user'      => 'No podemos encontrar un usuario con esa dirección de correo electrónico.',

    // Login page UI
    'tagline'                  => 'Documentación diaria de seguridad en obra',
    'feature_tests'            => 'AST, PTF, EPP, IHM y los formatos que tu empresa defina',
    'feature_health'           => 'Firma con reconocimiento facial y evidencia guardada',
    'feature_methods'          => 'Un trabajador, una identidad, aunque rote de contratista',
    'feature_reports'          => 'Planes de trabajo firmados y auditables',
    'signin_subtitle'          => 'Ingresa tus credenciales para continuar',
    'verify_report'            => 'Verificación de informes',
    'email'                    => 'Correo electrónico',
    'password'                 => 'Contraseña',
    'email_placeholder'        => 'tu@empresa.com',
    'show_password'            => 'Mostrar contraseña',
    'hide_password'            => 'Ocultar contraseña',
    'or_continue_with'         => 'o continúa con',
    'continue_with_google'     => 'Continuar con Google',
    'disclosure'               => 'Al iniciar sesión aceptas los :terms y la :privacy.',
    'terms_short'              => 'Términos',
    'privacy_short'            => 'Privacidad',
    'app_default_name'         => 'DOCUFIZ',

    // Modal de aceptación registrada de Términos/Privacidad (LPDP Ley 29733).
    'legal_modal_title'  => 'Términos y Política de Privacidad',
    'legal_modal_body'   => 'Para continuar usando el sistema necesitamos que revises y aceptes los Términos de Servicio y la Política de Privacidad (tratamiento de datos personales). Tu aceptación queda registrada con fecha y versión.',
    'legal_modal_check'  => 'He leído y acepto los Términos de Servicio y la Política de Privacidad.',
    'legal_modal_accept' => 'Aceptar y continuar',
    'terms'              => 'Términos de Servicio',
    'privacy'            => 'Política de Privacidad',
];