<?php

return [
    // Notification (bell)
    'notif_title' => 'Report to sign',
    'notif_body'  => 'You have :ref pending your review and signature.',
    'one_report'  => 'a report',
    'n_reports'   => ':count reports',

    // Inbox
    'menu'        => 'Approvals',
    'title'       => 'Approvals',
    'subtitle'    => 'Report requests waiting for your review and signature.',
    'tab_pending' => 'Pending',
    'tab_done'    => 'Completed',
    'empty'       => 'You have no requests pending signature.',
    'empty_done'  => 'You have not resolved any request yet.',
    'col_serial'  => 'Transformer',
    'col_title'   => 'Your role',
    'col_preparer'=> 'Sent by',
    'col_date'    => 'Sent',
    'fleet_one'   => 'Fleet',
    'reports_word'=> 'reports',
    'transformers'=> 'Transformers',
    'progress'    => ':approved of :total signed',
    'your_relation' => 'You sign as',
    'review'      => 'Review and sign',
    'view_draft'  => 'View draft (PDF)',
    'approve'     => 'Approve and sign',
    'reject'      => 'Reject',
    'reject_title'=> 'Reject request',
    'reject_reason' => 'Rejection reason',
    'reject_hint' => 'Explain what to fix; the request goes back to the preparer.',
    'rejected_badge' => 'Rejected',
    'approved_badge' => 'Approved',
    'confirm_approve' => 'Approve and sign this request?',

    // Signer ↔ report relation (closed, translatable keys)
    'relation' => [
        'prepared'   => 'Prepared by',
        'reviewed'   => 'Reviewed by',
        'approved'   => 'Approved by',
        'authorized' => 'Authorized by',
        'verified'   => 'Verified by',
        'endorsed'   => 'Endorsed by',
    ],

    // PDF / state
    'pdf_in_review'      => 'Under review — Draft',
    'pdf_in_review_note' => 'Preliminary document subject to approval. Not valid until issued.',
    'pdf_pending'        => 'This report is pending approval and cannot be downloaded yet.',

    // Messages
    'sent_ok'         => 'Report sent for approval. The signers were notified.',
    'already_pending' => 'This report already has an approval request in progress.',
    'share_sent'      => ':count report was sent for approval. Once approved, the link is sent to the recipient automatically.|:count reports were sent for approval. Once approved, the link is sent to the recipient automatically.',
    'fleet_label'     => ':customer — :count report|:customer — :count reports',
    'approved_ok'     => 'Request signed. If you were the last signer, it was issued.',
    'rejected_ok'     => 'Request rejected. The preparer was notified.',

    // ── My requests (requester side) ──────────────────────────────────────
    'my_requests_menu'     => 'My requests',
    'my_requests_title'    => 'My requests',
    'my_requests_subtitle' => 'Reports you sent for approval and their status.',
    'mr_tab_review'        => 'Under review',
    'mr_tab_approved'      => 'Approved',
    'mr_tab_rejected'      => 'Rejected',
    'mr_empty_review'      => 'You have no requests under review.',
    'mr_empty_approved'    => 'You have no approved requests yet.',
    'mr_empty_rejected'    => 'You have no rejected requests.',
    'mr_status_in_review'  => 'Under review',
    'mr_status_approved'   => 'Approved',
    'mr_status_rejected'   => 'Rejected',
    'mr_sent_on'           => 'Sent',
    'mr_issued_on'         => 'Issued',
    'mr_recipient'         => 'Recipient',
    'mr_customer'          => 'Customer',
    'mr_shared_badge'      => 'Link sent to client',
    'mr_signers'           => 'Signers',
    'mr_download'          => 'Download PDF',
    'mr_resubmit'          => 'Fix and resend',
    'mr_confirm_resubmit'  => 'Resend this request for approval?',
    'reject_reason_label'  => 'Rejection reason',
    'resubmit_ok'          => 'Request resent for approval. The signers were notified.',

    // Notifications to the requester
    'requester_approved_title'        => 'Request approved',
    'requester_approved_body'         => 'Your request :ref was approved and the report was issued.',
    'requester_approved_shared_body'  => 'Your request :ref was approved and the link was sent to the client.',
    'requester_rejected_title'        => 'Request rejected',
    'requester_rejected_body'         => 'Your request :ref was rejected. Check the reason and resend it.',
];
