<?php

return [
    'approval_out_of_order' => 'Not yet: :roles still has to sign, and comes first in the flow.',

    // The rule that always held: whoever does the work declares first what they
    // are going to do, and the supervisor authorises on top of that
    // declaration. Authorising earlier is signing a blank page. The server says
    // it when the signature is attempted and the plan repeats it on the blocked
    // row: one rule, one sentence.
    'approval_needs_representative' => 'Not yet: the workers’ representative still has to be designated. Nobody authorises the work until somebody answers for the team doing it.',

    // Signing screen. Read wearing a hard hat, in full sun, with the camera
    // open: short lines, each saying what to do rather than what is happening.
    'sign' => [
        'searching'          => 'Looking for a face…',
        'comparing'          => 'Comparing…',
        'evidence'           => 'No match: taking the photo for review',
        'frame_face'         => 'Frame your face',
        'enrolling'          => 'Registering face…',
        'enroll_progress'    => 'Registering face: :done of :total',
        'no_face_registered' => 'This person has no face on file. Keep your face in frame.',
        'enroll_failed'      => 'Could not register the face. Try again with better light.',
        'enroll_done'        => 'Face registered. Now sign.',
        'nobody_found'       => 'Nobody was detected in front of the camera. Nothing was recorded.',
        'challenge_failed'   => 'The face matches but the gesture was not completed. The signature is recorded and left pending supervisor review.',
        'failed'             => 'Could not complete the signature.',
        // Drawn signature. Captured once and reused, as in v1.
        'has_signature_on_file' => 'Signature already on file',
        'needs_signature'    => 'Signature not on file yet',
        'draw_title'         => 'Sign here',
        'draw_hint'          => 'First time signing in the system. The signature is stored and will not be asked for again: from now on, only the photo.',
        'draw_first'         => 'Sign in the box first.',
        'reuse_hint'         => 'The signature on file will be used. Only the photo is needed.',
        'replace'            => 'Update my signature',
        'clear'              => 'Clear',
        'take_photo_and_sign'=> 'Take photo and sign',
        'back_to_plan'       => 'Back to the plan',
        'back_to_plan_in'    => 'Back to the plan… :n',
        'no_target'          => 'No idea who is signing',
        'no_target_hint'     => 'Go back to the plan and press Sign on that person’s row.',
        'signature_required' => ':name has no signature on file: it has to be drawn before signing the plan.',
        'turn_head'          => 'Turn your head to one side',
        'nod_head'           => 'Nod your head',
        'back_center'        => 'Now look straight ahead again',
    ],

    // Pantalla de llenado de un formato del plan.
    'version'         => 'Version',
    'missing'         => 'Still missing',
    // What is missing is named by its label, not by the field code: this
    // warning is read by whoever is filling the form in on site. The
    // attachment is not a field and has no label, so its wording lives here.
    'missing_attachment' => 'the form file',
    'missing_required' => 'Still to fill in: :fields. Fill it in and hit Confirm again.',
    'readonly_notice' => 'This form is already confirmed. To change anything it has to be reopened, and that is recorded.',
    'reopen'          => 'Edit again',
    'reopen_title'    => 'Reopen this form?',
    'reopen_help'     => 'It goes back to draft so it can be corrected. It is recorded in the history: who reopened it and when. It will have to be confirmed again.',
    'reopened'        => 'Form reopened. Correct what you need and confirm it again.',
    // The server says it too, not just the screen: until now the lock on a
    // confirmed form lived only in the browser.
    'confirmed_reopen_first' => 'This form is confirmed. Reopen it before changing anything.',
    'plan_closed'     => 'The plan is closed: its forms are no longer editable.',
    'document'        => 'Document',
    'attach'          => 'Attach',
    'save'            => 'Save',
    'confirm'         => 'Confirm form',
    'mark_all'        => 'Mark all',

    // Avance de un campo compuesto: el índice de filas y las cabeceras
    // plegadas del EPP, el IHM y la matriz de riesgo. Ver la nota en `es`.
    'progress' => [
        'complete'      => 'Complete',
        'not_started'   => 'Not started',
        'missing'       => ':n left',
        'nonconforming' => 'Not compliant',
        'expand_all'    => 'Expand all',
        'collapse_all'  => 'Collapse all',
        'next'          => 'Next: :name',
        'checks'        => ':done of :total checks',
        'people_done'   => ':done of :total workers complete',
        'tools_done'    => ':done of :total tools complete',
        'hazards_rated' => ':done of :total hazards rated',
        'by_level'      => 'High: :high · Medium: :mid · Low: :low',
        'row'           => 'Row :n',
        'hazard'        => 'Hazard :n',
        'index_people'  => 'Workers in this form',
        'index_tools'   => 'Tools in this form',
        'index_hazards' => 'Hazards in this form',
    ],

    'status' => [
        'pending'   => 'Not started',
        'draft'     => 'Draft',
        'submitted' => 'Submitted',
        'confirmed' => 'Confirmed',
    ],

    // Campos de corrección: solo aparecen cuando algo sale no conforme.
    'correction_title' => 'Corrective action',
    'extra' => [
        'correction_measure'      => 'Corrective measure',
        'deadline_date'           => 'Deadline',
        'correction_verification' => 'Correction verified',
        'responsible'             => 'Responsible',
        'observation'             => 'Remark',
    ],

    // AST y PTF: actividad → peligro → control → severidad × probabilidad.
    'risk_matrix' => [
        'activity'     => 'Activity',
        'danger'       => 'Hazard',
        'risk'         => 'Risk',
        'control'      => 'Control',
        'severity'     => 'Severity',
        'probability'  => 'Probability',
        'search'       => 'Search the catalogue',
        'add_row'      => 'Add hazard',
        'remove_row'   => 'Remove this row',
        'empty'        => 'No hazards yet. Add the first one.',
        'row_incomplete' => 'This hazard is missing: :fields. A rated hazard is declared in full: what can happen, what it leads to and what control is in place.',
        'no_risk'      => 'Not assessed',
        'level_alto'   => 'High risk',
        'level_medio'  => 'Medium risk',
        'level_bajo'   => 'Low risk',
    ],

    // EPP: una fila por trabajador de la cuadrilla.
    'person_checklist' => [
        'no_people' => 'This work plan has no workers assigned: add them before filling this form.',
    ],

    // IHM: una fila por herramienta.
    'tool_checklist' => [
        'tool'        => 'Tool',
        'add_tool'    => 'Add tool',
        'remove_tool' => 'Remove this tool',
        'empty'       => 'No tools yet. Add the first one.',
    ],
    'signature_reason_placeholder' => 'Reason (optional)',
];
