<?php

return [
    'singular'      => 'Person',
    'plural'        => 'People',
    'record'        => 'person',
    'records'       => 'people',
    'new'           => 'Create person',
    'id'            => 'No.',

    'index_title'    => 'People',
    'index_subtitle' => 'Who works on site: their ID document, the companies they belong to and whether their face is enrolled for signing.',
    'create_title'   => 'Create person',
    'create_subtitle'=> 'Register the person with their ID document. Face and signature are captured later, on site.',
    'edit_title'     => 'Edit person',
    'delete_title'   => 'Delete person',
    'show_title'     => 'Person — Details',
    'trash_title'    => 'People trash',
    'form_create_hint' => 'Register the person with their ID document. Face and signature are captured later, on site.',
    'empty_hint'      => 'Create your first person or import a batch from Excel.',

    // ── Fields ──────────────────────────────────────────────────────────────
    'name'                 => 'First names',
    'name_help'            => 'Given names exactly as they appear on the ID document.',
    'name_placeholder'     => 'E.g.: Juan Carlos',
    'lastname'             => 'Last names',
    'lastname_help'        => 'Full last names exactly as they appear on the ID document.',
    'lastname_placeholder' => 'E.g.: Pérez Gómez',
    'full_name'            => 'Person',
    'document'             => 'ID document',
    'doc_type'             => 'Document type',
    'num_doc'              => 'Document number',
    'num_doc_help'         => 'Identifies the person. It cannot repeat within the same country.',
    'num_doc_placeholder'  => 'E.g.: 12345678',
    'num_doc_faltan'       => ':n digits to go.',
    'num_doc_falta_uno'    => '1 digit to go.',

    // RENIEC lookup. Peru and DNI only: a foreigner card, a PTP or a passport
    // are not in RENIEC.
    'dni_buscando'      => 'Checking with RENIEC…',
    'dni_encontrado'    => 'RENIEC data: name and surnames filled in.',
    'dni_no_encontrado' => 'RENIEC returned nothing for this DNI. Type the name by hand.',
    'dni_error'         => 'RENIEC could not be reached. Type the name by hand.',
    'dni_verificado'    => 'Name verified with RENIEC.',
    'dni_editar_a_mano' => 'Edit by hand',

    // Reference photo and stored signature. Requires `people.view_media`.
    'media_title'  => 'Photo and signature',
    'media_file'   => 'file',
    'media_help'   => 'Internal material: neither the worker nor the field profiles see it. The photo is for recognising the person; the signature is printed in the PDFs.',
    'photo'        => 'Reference photo',
    // It was missing, and the record printed «people.signature» raw under the trace.
    'signature'    => 'Signature',
    'no_photo'     => 'No photo',
    'no_signature' => 'No signature',
    'media_none'   => 'No material',
    'media_open'   => 'Enlarge',
    'upload'       => 'Upload',
    'replace'      => 'Replace',
    'photo_saved'     => 'Photo saved.',
    'signature_saved' => 'Signature saved.',
    'photo_source_uploaded' => 'Uploaded by hand',
    'photo_source_captured' => 'Captured while signing',
    'photo_source_migrated' => 'From the previous system',
    'signer_face'  => 'See the face they signed with',

    'country'              => 'Country',
    'country_help'         => 'Country of the document. Together with the number it defines the person uniqueness.',
    'origin'               => 'Origin',
    // No longer asked: the document type says it. In Peru a local carries a
    // DNI and someone from abroad carries a foreigner card, a PTP or a
    // passport, and the catalogue marks which is which.
    'origin_local'         => 'Local',
    'origin_foreign'       => 'Foreign',
    'birthdate'            => 'Date of birth',
    'birthdate_help'       => 'Optional. Used in personnel reports.',
    'roles'                => 'Site roles',
    'roles_help'           => 'What they sign on site. A supervisor only shows up in the plan approver picker if it is ticked here.',
    'roles_placeholder'    => 'Worker, Supervisor, HSE Supervisor…',
    // The field is disabled rather than hidden, and this says why: hiding it
    // left anyone who opened the form with a contractor selected unable to see
    // the roles at all, with no way to tell what they depended on.
    'roles_solo_los_mios'  => 'Only for your own company',
    'roles_elige_empresa'  => 'Pick the company first: site roles are only asked for your own people.',
    'roles_no_es_mi_empresa' => 'This person works for a contractor, and whoever authorises a plan belongs to the hiring company. Site roles are only asked for your own people — the company set under Settings → My company.',
    'companies'            => 'Companies',
    'companies_count'      => 'Company count',
    // Labels for the delete warning: «3 signatures depend on this record».
    'signatures_count'     => 'signatures',
    'approvals_count'      => 'plan approvals',
    'plans_count'          => 'work plans',
    'biometric'            => 'Face',
    'signatures'           => 'Signatures',
    'is_active'            => 'Status',
    'is_active_help'       => 'If inactive, the person will not show up when assigning workers to a plan.',
    'filter_name'          => 'First or last name',
    'search_placeholder'   => 'Search by first or last name…',
    'trash_search_placeholder' => 'Search by name or document…',

    'section_identity' => 'Identity',
    'section_optional' => 'Additional details',
    'section_work'     => 'Work',
    // Position and company were missing entirely from this screen, and in the
    // previous system both are mandatory on the worker record.
    'company'          => 'Company',
    'company_help'     => 'Company they work for. Determines which plans they can join.',
    'position'         => 'Position',
    'position_help'    => 'What they do on site: technician, supervisor, mechanic, electrician.',

    'role_worker'         => 'Worker',
    'role_supervisor'     => 'Supervisor',
    'role_hse_supervisor' => 'HSE supervisor',

    'biometric_yes' => 'Enrolled',
    'biometric_no'  => 'Not enrolled',

    // Withdrawing the registered face — see the note in the Spanish file.
    'biometric_forget'         => 'Withdraw face',
    'biometric_forget_hint'    => 'Deletes this person\'s face data. They can register it again whenever they want, giving their permission once more.',
    'biometric_forget_confirm' => 'Withdraw the registered face of :name? It is deleted, not disabled. Signatures already given are untouched: they still say how the person was identified that day.',
    'biometric_forgotten'      => 'Face withdrawn. Next time they sign they will have to register it again or sign by other means.',
    'biometric_none_to_forget' => 'This person has no registered face.',

    'edit_hint'   => 'Edit this record',
    'delete_hint' => 'Delete (goes to trash)',
    'restore_hint'=> 'Will go back to the main list.',

    'created' => 'Person created.',
    'saved'   => 'Person updated.',
    'deleted' => 'Person deleted.',

    'delete_about'                 => 'You are about to delete ":name". It will go to the trash.',
    'deleted_description_required' => 'Provide a reason for the deletion.',
    'deleted_description_min'      => 'Reason must be at least 3 characters.',
    'deleted_description_max'      => 'Reason cannot exceed 1000 characters.',

    // Export
    'export_filename'           => 'people_export',
    'import_template_filename'  => 'people-template.xlsx',
    'export_title'              => 'People Report',
    'export_limit_exceeded'     => 'The :format export exceeds the limit (:count rows vs :limit max). Use CSV for large datasets (no limit).',
    'export_format_limit_hint'  => 'Max :limit rows for this format. Use CSV for large datasets.',
    'export_no_limit_hint'      => 'No limit — recommended for large datasets.',

    // Validation
    'name_required'              => 'First names are required.',
    'lastname_required'          => 'Last names are required.',
    'name_and_lastname_required' => 'Creating a person requires both first and last names.',
    'name_unique'                => 'This person already exists.',
    'num_doc_required'           => 'The document number is required.',
    'num_doc_too_short' => 'A :type has at least :min characters.',
    'num_doc_too_long' => 'A :type has at most :max characters.',
    'num_doc_solo_cifras'      => 'A :type has digits only.',
    'num_doc_cifras_y_letras'  => 'A :type has digits and letters only.',
    'num_doc_sin_espacios'     => 'The document number has no spaces.',
    'num_doc_unique'             => 'A person with this document already exists in the same country.',
    'roles_solo_de_mi_empresa'   => 'Site roles are only given to your own people: whoever authorises a plan belongs to the hiring company.',
    'doc_type_invalid'           => 'Document type not valid for that country. Allowed: :types.',
    'doc_type_ninguno'           => 'No types for this country',
    'doc_type_sin_catalogo'      => 'This country has no person document in the catalogue. Create one under Document types, with scope «Person».',
    'doc_masked_notice'          => 'The document is hidden and cannot be changed: it needs the private data permission (people.view_private_info).',
    'country_required'           => 'Pick the document country.',
    'name_duplicate_in_batch'    => 'Duplicate document in the same batch.',
    'is_active_required'         => 'The status field is required.',
    'import_super_blocked'       => 'A super without an assigned workspace cannot import (document matching could update records from another workspace).',
    // The spreadsheet asks for the same as the form: without a company the
    // person cannot join any plan, and without a position nobody knows what
    // they do on site.
    'import_company_position_required' => 'Creating a person requires the company and the position (columns company and position).',
    'import_role_unknown'        => 'Unknown site role: ":value". Allowed: :roles.',
    'import_padded_zero'         => 'The leading zero Excel ate was put back: :value.',

    // Edit All
    'edit_all_title'    => 'People — Edit All',
    'edit_all_subtitle' => 'Fix first and last names across many people at once — the legacy migration brought plenty of them swapped. The document is not editable here.',
    'edit_all_changes'  => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all' => 'Save all',
    'edit_all_discard'  => 'Discard changes',
    'edit_all_no_results' => 'No people match the filter.',

    'table_headers' => [
        'editable_name'     => 'First names (editable)',
        'editable_lastname' => 'Last names (editable)',
        'editable_status'   => 'Status (editable)',
    ],

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Welcome to People',
        'step1_body'  => 'This is the crew that works on site. Quick tour in under a minute.',
        'step2_title' => 'Filters',
        'step2_body'  => 'Search by first name, last name or document, and filter by company, role, country and face enrollment.',
        'step3_title' => 'Saved views',
        'step3_body'  => 'Save your favorite filter + columns + sort combo and reapply with one click. Per-user.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Show/hide columns; your choice persists. Required ones cannot be hidden.',
        'step5_title' => 'Export & Import',
        'step5_body'  => 'Export to Excel/PDF/Word in the background — you will be notified. Import from Excel/CSV with preview.',
        'step6_title' => 'Edit many at once',
        'step6_body'  => '"Edit all" lets you fix first and last names across many people and save in one go.',
        'step7_title' => 'Favorites ★',
        'step7_body'  => 'The star ★ marks a person as a favorite. Favorites always show at the top of the list; each user has their own.',
        'step8_title' => 'Bulk operations',
        'step8_body'  => 'Select rows with the checkboxes — a bar appears to activate, deactivate or delete. Large batches run in the background.',
        'step9_title' => 'Need a refresher?',
        'step9_body'  => 'Reopen this tour anytime with the ? button. "Recent" in the avatar menu shows the last records you viewed.',
    ],
    'bulk_skipped_in_use'  => ':count could not be removed: they have work plans, signatures or approvals. Deactivate them instead.',
];
