<?php

return [
    'singular'      => 'Work plan',
    'plural'        => 'Work plans',
    'record'        => 'work plan',
    'records'       => 'work plans',
    'new'           => 'Create work plan',
    'id'            => 'No.',

    'index_title'    => 'Work plans',
    'index_subtitle' => 'Jobs scheduled on site, with their contractor, workers and safety forms.',
    'create_title'   => 'Create work plan',
    'create_subtitle'=> 'Record the job: who performs it, what kind of work it is and where it takes place.',
    'edit_title'     => 'Edit work plan',
    'delete_title'   => 'Delete work plan',
    'show_title'     => 'Work plan — Details',
    'trash_title'    => 'Work plans trash',
    'form_create_hint' => 'Record the job: who performs it, what kind of work it is and where it takes place.',
    'empty_hint'      => 'Create your first work plan or import a batch from Excel.',

    // ── Fields ──────────────────────────────────────────────────────────────
    'code'                 => 'Code',
    'num_os'               => 'Service order',
    'num_os_none'          => 'This job has no service order',
    'num_os_help'          => "Customer's service order number.",
    'num_os_placeholder'   => 'E.g.: OS-2024-1187',
    'description'          => 'Work description',
    'description_help'     => "What is going to be done, in the supervisor's own words. This is what people search when they cannot recall the code.",
    'description_placeholder' => 'E.g.: Preventive maintenance of medium-voltage switchgear',
    'company'              => 'Company',
    'company_help'         => 'Contractor performing the work.',
    'work_type'            => 'Work type',
    'work_type_help'       => 'Determines which safety forms the plan requires.',
    'work_location'        => 'Site',
    'work_location_help'   => 'Facility where the work takes place.',
    'workstation'          => 'Workstation',
    'workstation_help'     => 'Workstation within the site. Pick the site first.',
    'workstation_needs_location' => 'Pick a site first',
    'work_area'            => 'Area',
    'work_area_help'       => 'Area of the facility being worked on.',
    // They carry a time, and they say so: that is what separates them from a
    // calendar date and what makes "Worked time" mean anything.
    'date_start'           => 'Start date and time',
    'date_end'             => 'End date and time',
    'period_work'          => 'Work period',
    'worked_time'          => 'Worked time',
    'worked_time_open'     => 'In progress',
    'is_done'              => 'Status',
    'is_done_help'         => 'A plan is done once every required form is confirmed and every mandatory signature has been collected.',
    'cannot_edit_closed'   => 'This plan is closed: it is an archived document and cannot be edited. Reopen it first if it needs correcting.',
    'is_closed'            => 'Closed',
    'people_count'         => 'Workers',
    'forms_count'          => 'Forms',
    'registered_by'        => 'Registered by',

    'state_done'     => 'Done',
    'state_pending'  => 'Pending',
    'state_locked'   => 'Locked',
    'state_unlocked' => 'Unlocked',

    'section_work'     => 'Work and location',
    'section_schedule' => 'Dates',

    // ── The plan sheet: summary (default) and long view ─────────────────────
    'view_summary'  => 'Summary',
    'view_full'     => 'All details',
    'view_hint'     => 'Switch view',

    'summary_work'    => 'The job',
    'summary_no_desc' => 'No description',
    'summary_where'   => 'Where',
    'summary_when'    => 'When',
    'summary_same_day'=> 'Single day',

    'progress_title'      => 'Progress',
    'progress_forms'      => 'Forms filled in',
    'progress_signatures' => 'Workers who signed',
    'progress_approvals'  => 'Approvals signed',
    'progress_count'      => ':done of :total',
    'progress_empty'      => 'Nothing assigned',

    'missing_title'      => 'Left to close it',
    'missing_crew'       => 'Assign the workers: there are none yet.',
    'missing_forms'      => '{1} Fill in and confirm 1 form.|[2,*] Fill in and confirm :count forms.',
    'missing_signatures' => '{1} Collect 1 worker signature.|[2,*] Collect :count worker signatures.',
    'missing_approvals'  => '{1} 1 required approval missing: :roles.|[2,*] :count required approvals missing: :roles.',
    'missing_none'       => 'Nothing left: the plan can be marked as done.',
    'missing_done'       => 'This plan is done.',

    'technical_title' => 'Record details',

    'filter_search'            => 'Code, service order or description',
    'search_placeholder'       => 'Search by code, service order or description…',
    'trash_search_placeholder' => 'Search by code or service order…',

    'bulk_mark_done' => 'Mark as done',
    'bulk_reopen'    => 'Reopen',

    'edit_hint'   => 'Edit this record',
    'delete_hint' => 'Delete (goes to trash)',
    'restore_hint'=> 'Will go back to the main list.',

    'created' => 'Work plan created.',
    'saved'   => 'Work plan updated.',
    'deleted' => 'Work plan deleted.',

    'delete_about'                 => 'You are about to delete ":name". It will go to the trash.',
    'deleted_description_required' => 'Provide a reason for the deletion.',
    'deleted_description_min'      => 'Reason must be at least 3 characters.',
    'deleted_description_max'      => 'Reason cannot exceed 1000 characters.',

    // Export
    'export_filename'           => 'work_plans_export',
    'import_template_filename'  => 'work-plans-template.xlsx',
    'export_title'              => 'Work Plans Report',
    'export_limit_exceeded'     => 'The :format export exceeds the limit (:count rows vs :limit max). Use CSV for large datasets (no limit).',
    'export_format_limit_hint'  => 'Max :limit rows for this format. Use CSV for large datasets.',
    'export_no_limit_hint'      => 'No limit — recommended for large datasets.',

    // Validation
    'code_required'            => 'The plan code is required.',
    'code_unique'              => 'A work plan with this code already exists.',
    'description_required'     => 'The work description is required.',
    'company_required'         => 'Pick the company performing the work.',
    'work_type_required'       => 'Pick the work type.',
    'work_location_required'   => 'Pick the site where the work takes place.',
    'date_end_after'           => 'The end date cannot be earlier than the start date.',
    'is_done_required'         => 'The status field is required.',
    'import_super_blocked'     => 'A super without an assigned workspace cannot import (code matching could update records from another workspace).',

    // Edit All
    'edit_all_title'    => 'Work plans — Edit All',
    'edit_all_subtitle' => 'Fix the service order and status of several plans at once. The code is not editable here: it identifies the plan.',
    'edit_all_changes'  => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all' => 'Save all',
    'edit_all_discard'  => 'Discard changes',
    'edit_all_no_results' => 'No work plans match the filter.',

    'table_headers' => [
        'editable_num_os' => 'Service order (editable)',
        'editable_status' => 'Status (editable)',
    ],

    // ── Plan setup: crew, forms and approvers ───────────────────────────────
    'setup_blocked_done'   => 'This plan is done: its workers, forms and approvers can no longer be changed.',
    'setup_blocked_closed' => 'This plan is closed: its workers, forms and approvers can no longer be changed.',
    'setup_blocked_hint'   => 'This plan is read-only.',
    'state_closed'         => 'Closed',
    // The opposite of closed, for the index filter. Not "unlocked": an open
    // plan is one still being worked on.
    'state_open'           => 'Open',

    // Just "Workers". The previous system said "contractor" because only their
    // people went in, but the main company staffs its own plans too.
    'crew_title'    => 'Workers',
    'crew_summary'  => '{0} Nobody signed yet|{1} 1 of :total signed|[2,*] :signed of :total signed',
    'crew_empty'    => 'Nobody here yet. Scan the first worker’s document.',
    'crew_empty_why' => 'With no workers no form can be filled in, there is nobody to designate as representative and nothing can be signed. It is the first thing on the plan.',
    'crew_pending_tag' => 'Workers missing',
    'crew_add'      => 'Add worker',
    'crew_add_title'=> 'Add worker',
    'crew_search_placeholder' => 'Scan or type the worker’s document…',
    'crew_search_hint' => '{1} Type 1 character or more of the document.|[2,*] Scan the document or type its :count digits: it is added on its own.',
    'crew_keep_typing' => 'Some documents start like that. Keep typing to the end.',
    'crew_create_person' => 'Register this worker',
    'crew_create_person_title' => 'New worker',
    'crew_create_person_help' => 'Only what the plan does not already know. Company and country are taken from the plan, and once saved the worker joins the list.',
    'crew_create_person_ok' => 'Register and add',
    'crew_person_created' => ':name has been registered and added to the plan.',
    'crew_person_exists' => 'That document already belongs to :name. Search for them by document instead of registering them again.',
    'crew_no_results'         => 'That document is not registered yet.',
    'crew_remove'   => 'Remove from plan',
    'crew_remove_confirm' => 'Remove :name from this plan?',
    // Whether a person has a face on file belongs in the Workers module, where
    // it is registered. On the plan what matters is whether they signed, and when.
    'crew_enrolled'     => 'Face enrolled',
    'crew_not_enrolled' => 'No face enrolled',
    'crew_signed'       => 'Signed',
    'done_at_signed' => 'Signed on :when',
    'done_at_completed' => 'Completed on :when',
    'crew_signed_at'    => 'Signed on :when',
    'crew_pending'      => 'Signature missing',

    // How the signature came about. The time says WHEN; this says whether the
    // server recognised the face. A verified signature and one captured because
    // it did NOT recognise looked the same on the record, and the second is the
    // one to go and review.
    'sign_how'                 => 'How they signed',
    'sign_face_recognition'    => 'Face recognition',
    'sign_face_recognition_hint' => 'The server compared the face against :name\'s biometrics and it matched.',
    'sign_timeout_capture'     => 'Not recognised',
    'sign_timeout_capture_hint' => 'It did not match :name\'s biometrics. The photo was stored and the signature is pending review.',
    'sign_manual'              => 'Manual signature',
    'sign_manual_hint'         => 'Authorised by hand, with a reason. No face comparison took place.',
    'sign_migrated'            => 'From the previous system',
    'sign_migrated_hint'       => 'Signature brought over from the previous system. It did not record what was checked.',
    'sign_reused'              => 'Reused check',
    'sign_reused_hint'         => 'Reused the check :name already passed on this same plan.',
    'sign_pending_review'      => 'Pending review',

    // The signature's trail, opened by clicking the face. It was stored from
    // the start and shown on no screen at all.
    'sign_audit_open'      => 'See the signature: face and trail',
    'sign_audit_signed_at' => 'Signed',
    'sign_audit_match'     => 'Face match',
    'sign_audit_ip'        => 'From IP',
    'sign_audit_device'    => 'Device',
    'sign_audit_coords'    => 'Where',
    'sign_audit_browser'   => 'Browser',
    'sign_audit_reason'    => 'Reason for the manual signature',

    'crew_sign_hint'    => 'Sign for :name with face recognition',
    'crew_added'    => ':name was added to the plan.',
    'crew_removed'  => 'The worker was removed from the plan.',
    'crew_already_assigned'     => ':name is already on this plan.',
    // The reason and nothing else — see the Spanish note.
    'crew_signed_cannot_remove' => 'Cannot remove :name: they already signed the plan.',

    'forms_title'    => 'Documents',
    'forms_not_in_plan'  => 'Not required on this plan',
    'forms_toggle_hint'  => 'Require form :code on this plan, or not',
    'forms_open_hint'    => 'Open form :code to fill it in',
    // With the plan closed the form is looked at, not touched — the verb says so.
    'forms_view_hint'    => 'View form :code',
    'forms_pdf_hint'     => 'Download :code as PDF',
    'forms_subtitle' => 'The work type sets them. Add or drop one here.',
    'forms_summary'  => '{0} None filled in|[1,*] :done of :total filled in',

    // What came out wrong in a form. It is v1's integer `observations`, which
    // the four formats recalculated by themselves and was what the supervisor
    // read at a glance. A confirmed EPP with three worn harnesses is not the
    // same as a confirmed clean one.
    // Todos los papeles del plan de golpe: el `plan_exports_controller`
    // de la v1, que era como se mandaba una jornada fuera.
    'export_zip' => 'Download all',
    'export_zip_hint' => 'Downloads the plan\'s confirmed forms as a single ZIP, one PDF each. This is what gets sent to the client or to an inspection.',
    'export_zip_empty' => 'No form has been confirmed yet, so there is nothing to download.',

    'findings_count' => '{1} 1 finding|[2,*] :count findings',
    // A slot that sometimes holds a red warning and sometimes nothing reads as
    // "nobody has looked yet". This is a result, and it is the good one.
    'findings_none'  => 'No findings',
    'findings_hint'  => '{1} One answer came out non-conforming. The form still closes: what it cannot do is close without a record of it.|[2,*] :count answers came out non-conforming. The form still closes: what it cannot do is close without a record of it.',
    'forms_findings' => '{1} 1 finding|[2,*] :count findings',
    'forms_empty'    => 'This plan’s work type does not require any form.',
    'forms_add'      => 'Add form',
    'forms_add_title'=> 'Add a form to this plan',
    'forms_open'     => 'Open',
    'forms_pdf'      => 'PDF',
    'forms_remove'   => 'Remove from plan',
    'forms_remove_confirm'   => 'Remove form :code from this plan?',
    'forms_source_work_type' => 'From the work type',
    'forms_source_extra'     => 'Added to this plan',
    'forms_source_submitted' => 'Outside the standard',
    'forms_required'  => 'Required',
    'forms_optional'  => 'Optional',
    'forms_none_left' => 'There are no published forms left to add.',
    'form_added'      => 'Form :code was added to the plan.',
    'form_removed'    => 'Form :code was removed from the plan.',
    'form_already_required'     => 'This plan already requires form :code.',
    'form_not_published'        => 'Form :code is not published yet: it cannot be filled in.',
    'form_filled_cannot_remove' => 'Cannot remove form :code: it already has answers, attachments or signatures. Dropping it would destroy that day’s safety record.',
    // The catalogue requires it, not this plan. Say where to change it: a "no"
    // with no way forward is what makes people look for a workaround.
    'form_required_by_work_type' => 'Form :code is mandatory for :type work and cannot be dropped from a single plan. If it should no longer be required, change it on the work type.',

    'approvals_title'    => 'Approval flow',
    'approvals_subtitle' => 'Signed in order. Type the signer’s document to assign them.',
    'approvals_summary'  => '{0} None signed|[1,*] :done of :total signed',
    'approvals_empty'    => 'This plan has no approvers defined.',
    'approvals_add'      => 'Add approver',
    'approvals_add_title'=> 'Add an approver',
    'approval_role'      => 'Approving role',
    'approval_person'    => 'Person',
    'approval_unassigned'=> 'Unassigned',
    'approval_required'  => 'Required',
    'approval_optional'  => 'Optional',
    'approval_approved'  => 'Approved',
    'approval_pending'   => 'Pending',
    // "Blocked" used to read "Pending", the same word as one you can actually
    // sign. Two different states under one name are not a state.
    'approval_blocked'   => 'Waiting',
    'approval_added'     => 'Approver added to the plan.',
    'approval_role_taken'=> 'This plan already has a :role approval.',
    'approval_rules_empty' => 'No approval roles left for this plan.',

    // Assign and sign
    'approval_assign'    => 'Assign signer',
    'approval_change_hint' => 'Change who signs as :role',
    'approval_assign_hint' => 'Scan or type the document. If the person exists, their name appears.',
    'approval_assigned'  => ':name is now the signer.',
    'approval_change'    => 'Change',
    'approval_sign'      => 'Sign',
    'approval_no_one_with_role' => 'No :role with that document. Check the person has that role on their record.',
    'approval_wrong_role' => ':name is not a :role. Only someone with that role on their worker record can sign this approval.',
    'approval_person_taken' => ':name already signs another role on this plan. One person does not cover two signatures.',
    'approval_signed_cannot_reassign' => 'This approval is already signed: the signer cannot be changed. The signature is the proof of who took responsibility.',

    // ── Who answers for the workers ──────────────────────────────────────────
    //
    // This used to be one more row of the approval flow, the "executing
    // worker". It is not any more: it collected no signature of its own -- it
    // pointed at somebody who had already signed as a worker -- and living in
    // the flow it could be deleted or made optional from the country's rules,
    // leaving a plan with people on site and nobody answering for them.
    'representative'        => 'Workers’ representative',
    // Said INSIDE the missing-representative warning, and only once there is
    // somebody to pick: loose above the card it was a grey paragraph nobody
    // read, and before anyone has signed "picked among those who signed"
    // confuses rather than helps.
    'representative_help'   => 'They are picked among those who already signed and it can be anyone on the team: they do not have to be a boss or hold a special position.',
    'representative_none'   => 'The representative still has to be designated',
    'representative_none_why' => 'Nobody authorises the work until somebody answers for the team doing it. It is the only thing left before the approval flow can be signed.',
    'representative_pending'  => 'Missing',
    // "Designated", not "Approved": nobody approves anything here. Someone is
    // picked to answer, and the signature that counts is the one they already
    // gave as a worker.
    'representative_done'     => 'Designated',
    'representative_designate' => 'Designate',
    'representative_change' => 'Change',
    // Heads the candidate list, which now shows up directly instead of behind a
    // button: the set is closed and small —the people on this plan who already
    // signed, drawn right above— so it is shown and tapped. See the note in `es`.
    'representative_pick'   => 'Pick who answers for the team. They come from those who already signed and it can be anyone: they do not have to be a boss.',
    'representative_pick_other' => 'Pick someone else from those who already signed.',
    // Needing to designate somebody is not the same as having nobody to pick:
    // with the whole team unsigned there are no candidates, hence no button.
    'representative_needs_signature' => 'Nobody has signed yet',
    'representative_needs_signature_why' => 'The representative is picked among the workers who already signed: as soon as there is one signature you can designate them.',
    // The banner heading the flow while it is blocked, and the button that
    // leads to the one thing that unblocks it.
    'approvals_blocked_title' => 'The workers’ representative still has to be designated',
    'approvals_blocked_body'  => 'Nobody authorises the work until somebody answers for the team doing it. Until then no signer is assigned and nothing is signed.',
    'approvals_blocked_cta'   => 'Go and designate them',
    'approvals_blocked_tag'   => 'Waiting',
    'representative_no_results' => 'Nobody with that document among those who already signed. The representative comes from the workers on this plan who have already signed.',
    'representative_assigned' => ':name is now the workers’ representative.',
    'representative_not_in_crew' => ':name is not on this plan. The representative has to be one of the workers going out on site.',
    'representative_must_sign_first' => ':name has not signed on this plan yet. The representative is picked among those who already signed: that signature, with its photo and its time, is the one that counts.',

    // What the plan still needs to close itself. The previous system's two
    // conditions, and no others.
    'close_needs_corrections' => '{1} Verify the fix for 1 finding.|[2,*] Verify the fix for :count findings.',
    'close_still_missing' => 'It cannot be marked as done yet. Still missing: :fields',
    'reopened' => 'Plan reopened: you can correct it now. When you are done, press “Mark as done”.',
    'marked_done' => 'Plan marked as done.',
    'state_reopened' => 'Reopened',
    'views_label'    => 'View by state',
    'filter_reopened'       => 'Reopened',
    'filter_never_reopened' => 'Never reopened',
    'reopen' => 'Reopen',
    'reopen_hint' => 'Makes the plan, its workers, its forms and its representative editable again. Who reopened it is recorded.',
    'reopen_confirm' => 'The plan goes back to in progress and can be modified. Who reopened it and when is recorded.',
    'mark_done' => 'Mark as done',
    'mark_done_hint' => 'Closes the plan again, if nothing is missing.',
    'reopened_by' => 'Reopened by :name on :when',
    'close_needs_date_end'  => 'The end time of the work is missing.',
    // The v1 messages, word for word. There they blocked saving; here they
    // block closing, because the plan is assembled after it is created.
    'close_needs_crew'      => 'It must have at least 1 worker.',
    'close_needs_forms'     => 'It must have at least 1 form.',
    'close_needs_signatures'  => '{1} 1 worker signature is missing.|[2,*] :count worker signatures are missing.',
    'close_needs_forms_done'  => '{1} 1 form is still unconfirmed.|[2,*] :count forms are still unconfirmed.',
    'close_needs_approvals' => '{1} 1 mandatory approval is missing.|[2,*] :count mandatory approvals are missing.',
    'close_needs_representative' => 'The workers’ representative still has to be designated.',

    'approval_waits_prior' => 'Waiting on the :role signature.',

    // No "worker" here: that role stopped signing approvals the day the
    // representative left the flow, and the migration deleted its rules.
    'approver_role' => [
        'supervisor'     => 'Supervisor',
        'hse_supervisor' => 'HSE supervisor',
    ],

    'field_work_title'    => 'On-site work',
    'field_work_subtitle' => 'The screens used on the tablet, out on site.',
    'field_work_forms'    => 'Fill in forms',
    'field_work_sign'     => 'Sign with face recognition',

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Welcome to Work plans',
        'step1_body'  => "This is the day's work. Quick tour in under a minute.",
        'step2_title' => 'Filters',
        'step2_body'  => 'Search by code, service order or description, and filter by company, work type, site, status and dates.',
        'step3_title' => 'Saved views',
        'step3_body'  => 'Save your favorite filter + columns + sort combo and reapply with one click. Per-user.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Only the columns that matter on a tablet on site are shown by default. Add site, area, workers or description here when working on a desktop.',
        'step5_title' => 'Export & Import',
        'step5_body'  => 'Export to Excel/PDF/Word in the background — you will be notified. Import from Excel/CSV with preview.',
        'step6_title' => 'Edit many at once',
        'step6_body'  => '"Edit all" lets you fix the service order and status across many plans and save in one go.',
        'step7_title' => 'Favorites ★',
        'step7_body'  => 'The star ★ marks a plan as a favorite. Favorites always show at the top of the list; each user has their own.',
        'step8_title' => 'Bulk operations',
        'step8_body'  => 'Select rows with the checkboxes — a bar appears to mark as done, reopen or delete. Large batches run in the background.',
        'step9_title' => 'Need a refresher?',
        'step9_body'  => 'Reopen this tour anytime with the ? button. "Recent" in the avatar menu shows the last records you viewed.',
    ],
];
