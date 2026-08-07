<?php

return [
    // Excel-style editor bar, shared by every diagnostic test.
    'editor_hint'    => 'Edit like in Excel: Tab / Enter / arrow keys to move, and paste a range from Excel.',
    'save_all'       => 'Save',
    'save_help'      => 'Save the grid changes to the database.',
    'discard'        => 'Discard',
    'discard_help'   => 'Discard unsaved changes and revert to the saved data.',
    'import_help'    => 'How to paste samples from Excel (click to see the guide).',
    'unsaved'        => ':count unsaved change(s)',
    'all_saved'      => 'All saved',
    'delete_row'     => 'Delete row',
    'restore_row'    => 'Restore row',
    'row_needs_data' => 'Some rows are incomplete (missing date or a required value). Complete them before saving.',
    'explain_open'   => 'See how this result was calculated',
    'no_samples'     => 'No samples yet. Create the first one to diagnose.',
    'date'           => 'Date',
    'date_help'      => 'Sampling date (and time). Click the header to sort.',
    'report_number'  => 'Report No.',
    'laboratory'     => 'Laboratory',
    'add_lab'            => 'Add laboratory',
    'lab_name'          => 'Laboratory name',
    'lab_name_required' => 'The laboratory name is required.',
    'state_help'     => 'Diagnostic status (color scale). Click the header to sort.',
    'samples_help'   => 'Editable table of samples: one record per sampling.',
    'trends_help'    => 'Charts of how the values evolve over time.',
    'summary_tab'    => 'Summary',
    'summary_help'   => 'Diagnosis, conclusions and recommendations for this test.',
    'state'          => 'Status',
    'export'         => 'Export',
    'export_help'    => 'Download the samples as an Excel file (with their diagnosis).',

    // Semáforo conditions (5 levels). The value stored in data is an internal
    // CODE ('Muy Bueno'…'Muy Malo'); these are the user-facing labels, aligned
    // to the standard Health Index scale.
    'legend'         => 'Status scale:',
    'cond_muy_bueno' => 'Excellent',
    'cond_bueno'     => 'Good',
    'cond_medio'     => 'Fair',
    'cond_malo'      => 'Poor',
    'cond_muy_malo'  => 'Critical',

    // State/urgency line leading each test's conclusion (shared action scale).
    // Reinforces severity: imperative tone when compromised/critical.
    'urgency_routine'     => 'Normal state: no immediate action required.',
    'urgency_watch'       => 'Watch state: follow up and do not let it slide.',
    'urgency_investigate' => 'Compromised state: needs attention and prioritized analysis.',
    'urgency_critical'    => 'Critical state: high risk — imperative and immediate intervention; possible outage.',

    // "Import from Excel" mini guide (popover in the editor bar).
    'paste_guide_open'  => 'Import from Excel',
    'paste_guide_title' => 'Paste samples from Excel',
    'paste_guide_intro' => 'No file needed: copy the range in Excel and paste it into the grid.',
    'paste_guide_cols'  => 'Column order (no headers):',
    'paste_guide_step1' => 'In Excel, arrange the columns in this order and copy the range (Ctrl+C), without the header row.',
    'paste_guide_step2' => 'Here, click the Date cell of an empty row and paste (Ctrl+V). Rows are created automatically.',
    'paste_guide_step3' => 'Check the live diagnosis and click Save.',
    'paste_guide_date'  => 'The date goes as YYYY-MM-DD (or with time: YYYY-MM-DD HH:mm).',

    // Test names by code (for shared reports in another language; the `tests.name`
    // column stores Spanish only). Fallback: the DB name.
    'test_cromas'  => 'Chromatography (DGA)',
    'test_furanos' => 'Furans',
    'test_fiquis'  => 'Physical-chemical',
    'test_fpot'    => 'Power factor',

    // Synthesized verdict (per-test mini-summary + Summary tab).
    'verdict_no_data'   => 'No data to diagnose.',
    'verdict_no_fault'  => 'No active fault: gases below their typical values (IEC 60599).',
    'verdict_fault'     => 'Fault detected — :type',
    'verdict_ieee_note' => 'IEEE C57.104 flags Condition :n due to stricter limits; at normal gas levels this is NOT a real fault.',
    'verdict_dp'        => 'DP ≈ :dp',
    'verdict_paper_ok'  => 'Insulating paper in good condition',
    'verdict_paper_bad' => 'Insulating paper degradation',
    'verdict_life'      => ':p% of paper life consumed',
    'verdict_fpot'      => 'Power factor :v %',
    'verdict_fpot_ok'   => 'Insulation in good condition',
    'verdict_fpot_bad'  => 'High power factor',
    'verdict_co_low'    => 'CO₂/CO = :r → possible thermal degradation of the paper; worth reviewing (IEC 60599)',
    'verdict_co_high'   => 'CO₂/CO = :r → low-temperature oxidation/aging of the paper; low risk (IEC 60599)',
    'duval_no_fault'    => 'No active fault — Duval does not apply at normal gas levels (IEC 60599).',
    'summary_title'     => 'Transformer diagnosis',
    'summary_test'      => 'Test',
    'summary_verdict'   => 'Verdict',
    'duplicate_date'    => 'A sample with date :date already exists for this transformer.',
    'batch_too_many'    => 'Cannot save more than :max samples at once.',
    'save_failed'       => 'Could not save. Check the data and try again.',
    // Label of the norm limit line in the trend charts.
    'chart_limit'       => 'Limit',
    'sample_date_hint' => 'YYYY-MM-DD (time optional)',
];
