<?php

return [
    'singular' => 'Suscripción',
    'plural'   => 'Suscripciones',
    'tab_title' => 'Suscripción',

    // Estado actual
    'current_title'      => 'Suscripción actual',
    'no_active'          => 'Sin suscripción activa',
    'no_active_hint'     => 'Este workspace no tiene un plan activo. Creá una suscripción o iniciá un trial.',
    'plan'               => 'Plan',
    'status'             => 'Estado',
    'starts_at'          => 'Inicio',
    'ends_at'            => 'Fin',
    'trial_ends_at'      => 'Fin del trial',
    'days_remaining'     => 'Días restantes',
    'days_remaining_n'   => '{1} :count día restante|[2,*] :count días restantes',
    'amount_paid'        => 'Monto pagado',
    'payment_method'     => 'Método de pago',
    'notes'              => 'Notas',
    'created_by'         => 'Creada por',

    // Status labels
    'status_trial'     => 'Trial',
    'status_active'    => 'Activa',
    'status_expired'   => 'Expirada',
    'status_suspended' => 'Suspendida',
    'status_cancelled' => 'Cancelada',

    // Actions
    'create'             => 'Nueva suscripción',
    'create_hint'        => 'Registrar un pago manual o iniciar un período de prueba',
    'renew'              => 'Renovar',
    'renew_hint'         => 'Cortar la suscripción actual y crear una nueva (histórico preservado)',
    'cancel'             => 'Cancelar',
    'cancel_hint'        => 'Cancelar pero permitir uso hasta el fin del período pagado',
    'suspend'            => 'Suspender',
    'suspend_hint'       => 'Cortar acceso inmediato (pago fallido, fraude, etc.)',

    // Form
    'kind'               => 'Tipo',
    'kind_paid'          => 'Pago manual',
    'kind_trial'         => 'Período de prueba',
    'kind_paid_hint'     => 'Cliente pagó por un período fijo (ej. 1 año, 3 meses).',
    'kind_trial_hint'    => 'N días gratis. Cuando expira, el tenant queda sin plan hasta que pague.',
    'trial_days'         => 'Duración del trial (días)',
    'currency'           => 'Moneda',
    'payment_method_options' => [
        'manual'        => 'Manual',
        'bank_transfer' => 'Transferencia bancaria',
        'stripe'        => 'Stripe',
        'paddle'        => 'Paddle',
        'cash'          => 'Efectivo',
        'other'         => 'Otro',
    ],

    // Cancel modal
    'cancel_mode'        => 'Modo',
    'cancel_mode_cancel' => 'Cancelar (permite uso hasta fin de período)',
    'cancel_mode_suspend'=> 'Suspender (corta acceso inmediato)',
    'cancel_reason'      => 'Motivo',
    'cancel_reason_placeholder' => 'Por qué se cancela/suspende. Quedará registrado en auditoría.',

    // History
    'history_title'   => 'Histórico de suscripciones',
    'history_empty'   => 'Aún no hay suscripciones registradas.',
    'history_count'   => '{0} Sin registros|{1} 1 registro|[2,*] :count registros',

    // Flash messages (controller)
    'created'         => 'Suscripción creada.',
    'created_trial'   => 'Período de prueba iniciado.',
    'renewed'         => 'Suscripción renovada.',
    'cancelled'       => 'Suscripción cancelada.',
    'suspended'       => 'Suscripción suspendida.',

    // Trial banner (FASE 1C)
    'expires_in_warning' => 'Tu plan expira en :days días. Renová para evitar cortes.',
    'expired_warning'    => 'Tu suscripción venció. Funcionalidades restringidas hasta renovar.',

    // Email — subscription expiring soon (cron daily)
    'email_subject'      => 'Tu suscripción expira en :days días',
    'email_title'        => 'Tu plan vence en :days días',
    'email_greeting'     => 'Hola :name,',
    'email_body'         => 'Te avisamos que la suscripción del workspace ":workspace" vence en :days días. Te recomendamos renovar antes para evitar interrupciones del servicio.',
    'email_cta'          => 'Contáctanos para renovar y mantener tu acceso activo.',
    'email_support_hint' => 'Si necesitas ayuda, escríbenos a',
];
