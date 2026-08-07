<?php

return [
    // Notificación (campana)
    'notif_title' => 'Informe para firmar',
    'notif_body'  => 'Tienes :ref pendiente de tu revisión y firma.',
    'one_report'  => 'un informe',
    'n_reports'   => ':count informes',

    // Bandeja
    'menu'        => 'Aprobaciones',
    'title'       => 'Aprobaciones',
    'subtitle'    => 'Solicitudes de informes que esperan tu revisión y firma.',
    'tab_pending' => 'Pendientes',
    'tab_done'    => 'Completados',
    'empty'       => 'No tienes solicitudes pendientes de firma.',
    'empty_done'  => 'Todavía no resolviste ninguna solicitud.',
    'col_serial'  => 'Transformador',
    'col_title'   => 'Tu rol',
    'col_preparer'=> 'Enviado por',
    'col_date'    => 'Enviado',
    'fleet_one'   => 'Flota',
    'reports_word'=> 'informes',
    'transformers'=> 'Transformadores',
    'progress'    => ':approved de :total firmaron',
    'your_relation' => 'Firmas como',
    'review'      => 'Revisar y firmar',
    'view_draft'  => 'Ver borrador (PDF)',
    'approve'     => 'Aprobar y firmar',
    'reject'      => 'Rechazar',
    'reject_title'=> 'Rechazar solicitud',
    'reject_reason' => 'Motivo del rechazo',
    'reject_hint' => 'Explica qué corregir; la solicitud vuelve al preparador.',
    'rejected_badge' => 'Rechazada',
    'approved_badge' => 'Aprobada',
    'confirm_approve' => '¿Aprobar y firmar esta solicitud?',

    // Relación firmante ↔ informe (claves cerradas, traducibles)
    'relation' => [
        'prepared'   => 'Elaborado por',
        'reviewed'   => 'Revisado por',
        'approved'   => 'Aprobado por',
        'authorized' => 'Autorizado por',
        'verified'   => 'Verificado por',
        'endorsed'   => 'Visado por',
    ],

    // PDF / estado
    'pdf_in_review'      => 'En revisión — Borrador',
    'pdf_in_review_note' => 'Documento preliminar sujeto a aprobación. Sin validez hasta su emisión.',
    'pdf_pending'        => 'Este informe está pendiente de aprobación y todavía no puede descargarse.',

    // Mensajes
    'sent_ok'         => 'Informe enviado a aprobación. Se avisó a los firmantes.',
    'already_pending' => 'Este informe ya tiene una solicitud de aprobación en curso.',
    'share_sent'      => 'Se envió :count informe a aprobación. Al aprobarse, el enlace se manda solo al destinatario.|Se enviaron :count informes a aprobación. Al aprobarse, el enlace se manda solo al destinatario.',
    'fleet_label'     => ':customer — :count informe|:customer — :count informes',
    'approved_ok'     => 'Solicitud firmada. Si eras el último firmante, quedó emitida.',
    'rejected_ok'     => 'Solicitud rechazada. Se notificó al preparador.',

    // ── Mis solicitudes (lado del solicitante) ────────────────────────────
    'my_requests_menu'     => 'Mis solicitudes',
    'my_requests_title'    => 'Mis solicitudes',
    'my_requests_subtitle' => 'Informes que enviaste a aprobación y su estado.',
    'mr_tab_review'        => 'En revisión',
    'mr_tab_approved'      => 'Aprobadas',
    'mr_tab_rejected'      => 'Rechazadas',
    'mr_empty_review'      => 'No tienes solicitudes en revisión.',
    'mr_empty_approved'    => 'Todavía no tienes solicitudes aprobadas.',
    'mr_empty_rejected'    => 'No tienes solicitudes rechazadas.',
    'mr_status_in_review'  => 'En revisión',
    'mr_status_approved'   => 'Aprobada',
    'mr_status_rejected'   => 'Rechazada',
    'mr_sent_on'           => 'Enviada',
    'mr_issued_on'         => 'Emitida',
    'mr_recipient'         => 'Destinatario',
    'mr_customer'          => 'Cliente',
    'mr_shared_badge'      => 'Enlace enviado al cliente',
    'mr_signers'           => 'Firmantes',
    'mr_download'          => 'Descargar PDF',
    'mr_resubmit'          => 'Corregir y reenviar',
    'mr_confirm_resubmit'  => '¿Reenviar esta solicitud a aprobación?',
    'reject_reason_label'  => 'Motivo del rechazo',
    'resubmit_ok'          => 'Solicitud reenviada a aprobación. Se avisó a los firmantes.',

    // Notificaciones al solicitante
    'requester_approved_title'        => 'Solicitud aprobada',
    'requester_approved_body'         => 'Tu solicitud :ref fue aprobada y el informe quedó emitido.',
    'requester_approved_shared_body'  => 'Tu solicitud :ref fue aprobada y el enlace se envió al cliente.',
    'requester_rejected_title'        => 'Solicitud rechazada',
    'requester_rejected_body'         => 'Tu solicitud :ref fue rechazada. Revisa el motivo y reenvíala.',
];
