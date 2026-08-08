<?php

return [
    'approval_out_of_order' => 'Not yet: :roles still has to sign, and comes first in the flow.',
    // Pantalla de llenado de un formato del plan.
    'version'         => 'Version',
    'missing'         => 'Still missing',
    'readonly_notice' => 'This form is already confirmed: it can be viewed, but not changed.',
    'document'        => 'Document',
    'attach'          => 'Attach',
    'save'            => 'Save',
    'confirm'         => 'Confirm form',
    'mark_all'        => 'Mark all',

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
        'control'      => 'Control',
        'severity'     => 'Severity',
        'probability'  => 'Probability',
        'search'       => 'Search the catalogue',
        'add_row'      => 'Add hazard',
        'remove_row'   => 'Remove this row',
        'empty'        => 'No hazards yet. Add the first one.',
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
];
