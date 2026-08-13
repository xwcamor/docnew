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
        'no_answer' => 'No answer',
        'observations' => 'Observations',

        'attachments'   => 'Attached document',
        'fingerprint'   => 'SHA-256 fingerprint',
        'attachment_not_embeddable' => 'The attachment is not an image and cannot be embedded; it is identified by its fingerprint.',

        'signatures'      => 'Recorded signatures',
        'no_signatures'   => 'This submission has no recorded signatures.',
        // The drawn signature, not the face: it is what you expect to see
        // next to a name on a signed document, and the face only exists
        // when recognition failed.
        'signature'       => 'Signature',
        'signer'          => 'Signer',
        'document_number' => 'ID number',
        'signed_at'       => 'Time',
        'col_method'      => 'Method',
        'pending_review'  => 'PENDING REVIEW',
        'reviewed'        => 'Verified',
        // The percentage, not the distance: whoever reads the report has no
        // reason to know that «0.15» is good and «0.55» is not.
        'match'           => 'Face match :value %',
        'override_reason' => 'Reason',

        // ── Risk matrix (AST and PTF) ────────────────────────────────────
        // See the Spanish file: the level suffixes are domain KEYS from
        // `config.levels`, Spanish on purpose, not words to translate.
        'risk_matrix' => [
            'activity_n'         => 'Activity :n',
            'unnamed_activity'   => 'Unnamed activity',
            'no_hazards'         => 'This activity recorded no hazards.',
            'evaluation'         => 'Assessment',
            'col_danger'         => 'Hazard',
            'col_risk'           => 'Risk',
            'col_control'        => 'Control',
            'col_probability'    => 'Probability',
            'col_severity'       => 'Severity',
            'col_level'          => 'Level',
            'total_hazards'      => 'Hazards: :count',
            'hazards_rated'      => ':done of :total assessed',
            'worst'              => 'Worst: :level',
            'not_assessed'       => 'Not assessed',
            'not_assessed_count' => ':count not assessed',
            'incomplete_count'   => ':count incomplete',
            'missing'            => 'Missing',
            'level_alto'         => 'High',
            'level_medio'        => 'Medium',
            'level_bajo'         => 'Low',
        ],

        // ── Question bank (PTF «Pare, Tome 5») ───────────────────────────
        'question_bank' => [
            'number'          => 'No.',
            'question'        => 'Question',
            'answer'          => 'Answer',
            'answered'        => ':done of :total questions answered',
            'unanswered'      => ':count unanswered',
            'observations'    => '{1} 1 observation — question :list|[2,*] :count observations — questions :list',
            'no_observations' => 'No observations',
            'observation'     => 'OBSERVATION',
            'notice'          => 'Every "No" is an observation: do not start work until it has been resolved with the HSE supervisor.',
            'untitled'        => 'Question with no text',
            'outside_catalog' => 'Answer to a question no longer in this version of the form.',
        ],

        // ── PPE per worker ───────────────────────────────────────────────
        'person_checklist' => [
            'summary'                 => ':people workers inspected · :issues non-conformities',
            'worker'                  => 'Worker',
            'status'                  => 'Status',
            'issues'                  => ':count non-conformity|:count non-conformities',
            'pending'                 => ':count unanswered|:count unanswered',
            'all_ok'                  => 'No findings',
            'unnamed_worker'          => 'Worker :number',
            'unknown_item'            => 'Unidentified item',
            'correction_measure'      => 'Corrective action',
            'deadline_date'           => 'Deadline',
            'correction_verification' => 'Corrective action verification',
            'answers' => [
                'compliant'      => 'Compliant',
                'not_applicable' => 'Not applicable',
                'non_compliant'  => 'Non-compliant',
            ],
        ],

        // ── Tool inspection (IHM) ────────────────────────────────────────
        'tool_checklist' => [
            'points'                  => 'Inspection points',
            'number'                  => 'No.',
            'tool'                    => 'Tool',
            'status'                  => 'Status',
            'conforming'              => 'Compliant',
            'nonconforming'           => 'Non-compliant',
            'incomplete'              => 'Incomplete',
            'unanswered'              => 'Not answered',
            'unnamed_point'           => 'Unnamed point',
            'unnamed_tool'            => 'Unnamed tool',
            'code'                    => 'Code',
            'quantity'                => 'Qty',
            'not_recorded'            => 'not recorded',
            'correction_measure'      => 'Corrective measure',
            'deadline_date'           => 'Deadline',
            'responsible'             => 'Responsible',
            'correction_verification' => 'Correction verified',
        ],

        'methods' => [
            'face_recognition' => 'Face recognition',
            'timeout_capture'  => 'Timeout capture',
            'manual'           => 'Authorized manual signature',
            'reused'           => 'Reused signature',
            'migrated'         => 'Migrated from the previous system',
        ],


        'formal_signers' => 'Workspace signatures',

        'verify_id'    => 'Verification ID',
        'generated_at' => 'Generated on',
        'page'         => 'Page',
    ],
];
