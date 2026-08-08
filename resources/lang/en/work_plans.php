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
    'code_help'            => 'Generated on save: country, year, day of the work and its number within that day. Never repeats.',
    'code_auto'            => 'Assigned on save — the running number for the day of the work.',
    'num_os'               => 'Service order',
    'num_os_help'          => "Customer's service order number, if any.",
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
    'crew_add'      => 'Add worker',
    'crew_add_title'=> 'Add worker',
    'crew_search_placeholder' => 'Scan or type the worker’s document…',
    'crew_search_hint'        => 'Type the full document number (8 digits).',
    'crew_no_results'         => 'That document is not registered. Register the worker first.',
    'crew_remove'   => 'Remove from plan',
    'crew_remove_confirm' => 'Remove :name from this plan?',
    // Whether a person has a face on file belongs in the Workers module, where
    // it is registered. On the plan what matters is whether they signed, and when.
    'crew_enrolled'     => 'Face enrolled',
    'crew_not_enrolled' => 'No face enrolled',
    'crew_signed'       => 'Signed',
    'crew_signed_at'    => 'Signed on :when',
    'crew_pending'      => 'Signature missing',
    'crew_sign_hint'    => 'Sign for :name with face recognition',
    'crew_added'    => ':name was added to the plan.',
    'crew_removed'  => 'The worker was removed from the plan.',
    'crew_already_assigned'     => ':name is already on this plan.',
    'crew_signed_cannot_remove' => 'Cannot remove :name: they already signed this plan. Their signature proves they were on site and attended the briefing, so the record is kept.',

    'forms_title'    => 'Safety forms',
    'forms_not_in_plan'  => 'Not required on this plan',
    'forms_toggle_hint'  => 'Require form :code on this plan, or not',
    'forms_open_hint'    => 'Open form :code to fill it in',
    'forms_pdf_hint'     => 'Download :code as PDF',
    'forms_subtitle' => 'The work type sets them. Add or drop one here.',
    'forms_summary'  => '{0} None filled in|[1,*] :done of :total filled in',
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
    // Why an approval cannot be signed yet. The previous system simply hid
    // these; showing them greyed out with the reason is better — the whole path
    // is visible without being able to skip a step.
    // What the plan still needs to close itself. The previous system's two
    // conditions, and no others.
    'close_needs_date_end'  => 'The end time of the work is missing.',
    // The v1 messages, word for word. There they blocked saving; here they
    // block closing, because the plan is assembled after it is created.
    'close_needs_crew'      => 'It must have at least 1 worker.',
    'close_needs_forms'     => 'It must have at least 1 form.',
    'close_needs_signatures'  => '{1} 1 worker signature is missing.|[2,*] :count worker signatures are missing.',
    'close_needs_forms_done'  => '{1} 1 form is still unconfirmed.|[2,*] :count forms are still unconfirmed.',
    'close_needs_approvals' => '{1} 1 mandatory approval is missing.|[2,*] :count mandatory approvals are missing.',

    // The v1 rule: until the executing worker signs their approval, the rest
    // were not even shown. Not "the crew must sign" -- those are attendance
    // signatures and do not govern authorisation.
    'approval_waits_worker' => ':role has to sign first.',
    'approval_waits_prior' => 'Waiting on the :role signature.',

    'approver_role' => [
        'worker'         => 'Worker',
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
