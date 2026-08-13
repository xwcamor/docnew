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
        // Same, without knowing why: the signature was stored with its photo and
        // does not count until a supervisor looks at it. See the note in `es`.
        'pending_review'     => 'The signature is recorded, but the face could not be verified. A supervisor will review it.',
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
        // Only shown when the signature is left pending review: a clean one goes
        // back to the plan on its own. `back_to_plan_in` («Back to the plan… :n»)
        // lived here too — the button countdown. It went with it. See `es`.
        'back_to_plan'       => 'Back to the plan',
        'no_target'          => 'No idea who is signing',
        'no_target_hint'     => 'Go back to the plan and press Sign on that person’s row.',
        'signature_required' => ':name has no signature on file: it has to be drawn before signing the plan.',
        'left_pending'       => 'Signature recorded. It is left pending supervisor review.',
        'verified'           => 'Signature verified.',
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
    // Said by the server when someone tries to confirm through the API with
    // the form half done. It no longer names any button: «hit Confirm again»
    // talked about a button that is gone — saving and confirming are one
    // «Save changes» now. Ver la nota en `es`.
    'missing_required' => 'Still to fill in: :fields.',
    // The friendly notice under the list of missing fields. Saving half-done
    // work is not an error. Ver la nota en `es`.
    'missing_help'    => 'What you saved is already saved and the form stays in draft. Fill in what is missing and press Save changes again.',
    // The word next to the label of a missing field: red never goes alone
    // (docs/UI.md §5).
    'field_missing'   => 'Not filled in',
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
    'attach_many'     => 'Attach :count files',
    'attach_drag_strong'   => 'Drag your files here',
    'attach_drag_or_click' => 'or tap to browse',
    'attach_formats_hint'  => 'Photos (JPG, PNG, WEBP) or PDF. Up to 8 MB each.',
    'attach_bad_type' => 'is neither a photo nor a PDF',
    'attach_too_big'  => 'is over 8 MB',
    'attach_rejected' => 'These files will not be uploaded',
    'no_attachments'  => 'No file was attached.',
    'detach_confirm'  => 'Remove this file from the form?',
    'detached_flash'  => 'File removed.',
    'attach_full'     => '{1} This field takes a single file and already has it. Remove it to add another.|[2,*] This field takes :max files and already has them. Remove one to add another.',
    'attach_max_files' => '“:label” takes at most :max files.',
    'attach_field_mimes' => ':name does not belong here: this field only takes :mimes.',
    'signature_save'   => 'Save signature',
    'signature_redo'   => 'Sign again',
    'signature_redo_confirm' => 'Delete this signature and sign again?',
    'signature_none'   => 'Not signed.',
    // The ONLY action button in the footer: saving tries to confirm in the
    // same gesture and the server decides. The `confirm` key left with the
    // second button. Ver la nota en `es`.
    'save'            => 'Save changes',
    // The two endings of that button, told by the server.
    'saved_partial'   => 'Changes saved.',
    'saved_confirmed' => 'Form saved and confirmed.',
    // Flashes that used to live hardcoded in the controller. Ver la nota en `es`.
    'attached_flash'  => '{1} Document attached.|[2,*] :count documents attached.',
    'confirmed_flash' => 'Form confirmed.',
    'mark_all'        => 'Mark all',

    // The way out of the footer, the same in both states. Ver la nota en `es`.
    'back_to_plan'    => 'Back to the plan',
    'leave_title'     => 'Leave without saving?',
    'leave_help'      => 'Anything you typed since the last save is lost. What you already saved stays.',
    'leave_discard'   => 'Leave without saving',
    'leave_stay'      => 'Stay here',

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
        // El banco de preguntas no tiene índice: su avance es esta línea.
        // Ver la nota en `es`.
        'questions_done' => ':done of :total questions answered',
        // Lo que dice un campo compuesto obligatorio que un guardado dejó
        // pendiente (prop `faltante`). Ver la nota en `es`.
        'required_hazards'   => '{0} Required: add an activity and rate its hazards.|{1} Required: 1 hazard still to rate.|[2,*] Required: :count hazards still to rate.',
        'required_people'    => '{1} Required: 1 worker still to complete.|[2,*] Required: :count workers still to complete.',
        'required_tools'     => '{0} Required: add at least one tool.|{1} Required: 1 tool still to complete.|[2,*] Required: :count tools still to complete.',
        'required_questions' => '{1} Required: 1 question still to answer.|[2,*] Required: :count questions still to answer.',
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

    // AST y PTF: el documento es una lista de ACTIVIDADES y, dentro de cada
    // una, sus peligros — peligro → riesgo → control → probabilidad →
    // severidad → nivel. Ése es el orden de las columnas de la v1.
    'risk_matrix' => [
        'activity'     => 'Activity',
        'danger'       => 'Hazard',
        'risk'         => 'Risk',
        'control'      => 'Control',
        'severity'     => 'Severity',
        'probability'  => 'Probability',
        // Sexta columna de la tabla vieja. No se teclea: sale de la matriz.
        'level'        => 'Level',
        // It said «Search the catalogue» while this was a closed select. It is
        // free text now, as in v1: the catalogue suggests, it does not rule.
        'search'       => 'Type, or pick a suggestion',
        'add_row'      => 'Add hazard',
        'remove_row'   => 'Remove this row',
        // La papelera de cada fila del modo tabla pregunta antes. Ver `es`.
        'remove_row_confirm' => 'Remove this hazard?',
        // La palabra junto al rojo de una celda vacía del modo tabla. Ver `es`.
        'cell_missing' => 'Missing',
        // Cabecera de cada bloque, como el «Actividad 1» del formato de papel.
        'activity_n'    => 'Activity :n',
        'activity_hint' => 'Name the activity: it is the heading of the hazards below it.',
        'add_activity'  => 'Add activity',
        'remove_activity' => 'Remove this activity',
        // Quitar la actividad se lleva sus peligros por delante, así que se
        // dice cuántos son antes de hacerlo.
        'remove_activity_confirm' => '{0} Remove this activity?|{1} Remove this activity and its hazard?|[2,*] Remove this activity and its :count hazards?',
        'empty'        => 'No activities yet. Add the first one.',
        'row_incomplete' => 'This hazard is missing: :fields. A rated hazard is declared in full: what can happen, what it leads to and what control is in place.',
        // La regla del servidor dicha fila a fila y antes de guardar. Ver `es`.
        'row_missing'  => 'Missing: :fields',
        'incomplete'   => 'Incomplete',
        'no_risk'      => 'Not assessed',
        'level_alto'   => 'High risk',
        'level_medio'  => 'Medium risk',
        'level_bajo'   => 'Low risk',
    ],

    // EPP: una fila por trabajador del plan.
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
    // Face match as a percentage. Internally it is a distance —0 is the same
    // face— which reads backwards from how people think about it.
    'match_percent' => 'Face match: :percent%',
    'signature_reason_placeholder' => 'Reason (optional)',
];
