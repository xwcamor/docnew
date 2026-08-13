<?php

return [
    /*
     * Textos del PDF firmado de una entrega de formato: el documento que la
     * empresa conserva. Mismas claves que en español — si falta una, el PDF
     * imprime la clave cruda delante del cliente.
     */
    'pdf' => [
        'title'         => 'Safety form',
        'plan'          => 'Work plan',
        'code'          => 'Code',
        'work_order'    => 'Work order',
        'company'       => 'Contractor',
        'tax_id'        => 'Document',
        'work_type'     => 'Work type',
        'location'      => 'Location',
        'date_start'    => 'Start',
        'date_end'      => 'End',
        'description'   => 'Work description',
        'submitted_at'  => 'Form submitted',
        'form_version'  => 'Version',
        'status'        => 'Status',

        'statuses' => [
            'draft'     => 'Draft',
            'submitted' => 'Submitted',
            'confirmed' => 'Confirmed',
        ],

        'content'   => 'Form content',
        'section'   => 'Section :number',
        'no_answer' => 'No answer',
        'observations' => 'Observations',

        'attachments'   => 'Attached document',
        'fingerprint'   => 'SHA-256 fingerprint',
        'attachment_not_embeddable' => 'The attachment is not an image and cannot be embedded; it is identified by its fingerprint.',

        'signatures'      => 'Recorded signatures',
        'no_signatures'   => 'This submission has no recorded signatures.',
        'evidence'        => 'Evidence',
        'signer'          => 'Signer',
        'document_number' => 'ID number',
        'role'            => 'Signed role',
        'signed_at'       => 'Time',
        'col_method'      => 'Method',
        'no_evidence'     => 'No photo',
        'pending_review'  => 'PENDING REVIEW',
        'reviewed'        => 'Verified',
        // The percentage, not the distance: whoever reads the report has no
        // reason to know that «0.15» is good and «0.55» is not.
        'match'           => 'Face match :value %',
        'override_reason' => 'Reason',

        'methods' => [
            'face_recognition' => 'Face recognition',
            'timeout_capture'  => 'Timeout capture',
            'manual'           => 'Authorized manual signature',
            'reused'           => 'Reused signature',
            'migrated'         => 'Migrated from the previous system',
        ],

        'roles' => [
            'worker'         => 'Worker',
            'supervisor'     => 'Supervisor',
            'hse_supervisor' => 'HSE supervisor',
        ],

        'formal_signers' => 'Workspace signatures',

        'verify_id'    => 'Verification ID',
        'generated_at' => 'Generated on',
        'page'         => 'Page',
    ],
];
