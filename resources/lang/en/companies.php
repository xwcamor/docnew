<?php

return [
    'singular'      => 'Company',
    'plural'        => 'Companies',
    'record'        => 'company',
    'records'       => 'companies',
    'new'           => 'Create company',
    'id'            => 'No.',

    'index_title'    => 'Companies',
    'index_subtitle' => 'Contractors that carry out the work on site.',
    'create_title'   => 'Create company',
    'create_subtitle'=> 'Register a contractor with its tax ID and legal name.',
    'edit_title'     => 'Edit company',
    'delete_title'   => 'Delete company',
    'show_title'     => 'Company — Details',
    'trash_title'    => 'Companies trash',
    'form_create_hint' => 'Register a contractor with its tax ID and legal name.',
    'empty_hint'      => 'Create your first company or import a batch from Excel.',

    // ── Fields ──────────────────────────────────────────────────────────────
    'name'                     => 'Name',
    'name_help'                => 'Short name the company is known by on site (e.g. HITACHI, LIMTEK).',
    'name_placeholder'         => 'E.g.: HITACHI',
    'complete_name'            => 'Legal name',
    'complete_name_help'       => 'Full name exactly as it appears on the company registration.',
    'complete_name_placeholder'=> 'E.g.: Hitachi Energy Perú S.A.',
    'num_doc'                  => 'Tax ID',
    'num_doc_help'             => 'Tax identification number. It cannot repeat within the same country.',
    'num_doc_placeholder'      => 'E.g.: 20512345678',
    'country'                  => 'Country',
    'country_help'             => 'Country where the company is registered. Together with the tax ID it defines uniqueness.',
    'people_count'             => 'People',
    'plans_count'              => 'Plans',
    'is_active'                => 'Status',
    'is_active_help'           => 'If inactive, the company will not show up when creating a work plan.',
    'filter_name'              => 'Name, legal name or tax ID',
    'search_placeholder'       => 'Search by name, legal name or tax ID…',

    'edit_hint'   => 'Edit this record',
    'delete_hint' => 'Delete (goes to trash)',
    'restore_hint'=> 'Will go back to the main list.',

    'created' => 'Company created.',
    'saved'   => 'Company updated.',
    'deleted' => 'Company deleted.',

    // What blocks a deletion: a company with plans or people behind it is
    // deactivated, not removed from the list (docs/UI.md §6).
    'in_use_cannot_delete_plans'  => 'Cannot delete: it has :count work plan(s) under its name. Deactivate it so it stops showing up on new plans.',
    'in_use_cannot_delete_people' => 'Cannot delete: it has :count linked person(s). Deactivate it so it stops showing up on new plans.',
    'bulk_skipped_in_use'         => ':count company(ies) were not deleted: they have plans or people under their name.',
    'delete_dependents_title'     => 'What depends on this company',
    'delete_dependents_plans'     => ':count work plan(s)',
    'delete_dependents_people'    => ':count linked person(s)',

    'delete_about'                 => 'You are about to delete ":name". It will go to the trash.',
    'deleted_description_required' => 'Provide a reason for the deletion.',
    'deleted_description_min'      => 'Reason must be at least 3 characters.',
    'deleted_description_max'      => 'Reason cannot exceed 1000 characters.',

    // Export
    'export_filename'           => 'companies_export',
    'import_template_filename'  => 'companies-template.xlsx',
    'export_title'              => 'Companies Report',
    'export_limit_exceeded'     => 'The :format export exceeds the limit (:count rows vs :limit max). Use CSV for large datasets (no limit).',
    'export_format_limit_hint'  => 'Max :limit rows for this format. Use CSV for large datasets.',
    'export_no_limit_hint'      => 'No limit — recommended for large datasets.',

    // Validation
    'name_required'            => 'The company name is required.',
    'name_unique'              => 'A company with this name already exists.',
    'complete_name_required'   => 'The legal name is required.',
    'num_doc_required'         => 'The tax ID is required.',
    'num_doc_unique'           => 'A company with this tax ID already exists in the same country.',
    'country_required'         => 'Pick the company country.',
    'name_duplicate_in_batch'  => 'Duplicate name in the same batch.',
    'is_active_required'       => 'The status field is required.',
    'import_super_blocked'     => 'A super without an assigned workspace cannot import (name matching could update records from another workspace).',
    // Import errors: the tax ID column is NOT NULL in the table, so an empty
    // cell can never reach the database.
    'import_err_num_doc_required' => 'The tax ID is missing. It is required to create a company.',
    'import_err_num_doc_too_long' => 'The tax ID exceeds 20 characters.',
    'import_err_no_country'       => 'Your user has no country assigned and country is required. Ask an administrator to assign one before importing.',

    // Edit All
    'edit_all_title'    => 'Companies — Edit All',
    'edit_all_subtitle' => 'Edit name and status of multiple companies at once. Click "Save all" to confirm, "Cancel" to discard.',
    'edit_all_changes'  => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all' => 'Save all',
    'edit_all_discard'  => 'Discard changes',
    'edit_all_no_results' => 'No companies match the filter.',

    'table_headers' => [
        'editable_name'   => 'Name (editable)',
        'editable_status' => 'Status (editable)',
    ],

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Welcome to Companies',
        'step1_body'  => 'These are the contractors that carry out the work. Quick tour in under a minute.',
        'step2_title' => 'Filters',
        'step2_body'  => 'Search and filter by name, tax ID, legal name, country and status. Active filters appear as chips.',
        'step3_title' => 'Saved views',
        'step3_body'  => 'Save your favorite filter + columns + sort combo and reapply with one click. Per-user.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Show/hide columns; your choice persists. Required ones cannot be hidden.',
        'step5_title' => 'Export & Import',
        'step5_body'  => 'Export to Excel/PDF/Word in the background — you will be notified. Import from Excel/CSV with preview.',
        'step6_title' => 'Edit many at once',
        'step6_body'  => '"Edit all" lets you modify name and status across many companies and save in one go.',
        'step7_title' => 'Favorites ★',
        'step7_body'  => 'The star ★ marks a company as a favorite. Favorites always show at the top of the list; each user has their own.',
        'step8_title' => 'Bulk operations',
        'step8_body'  => 'Select rows with the checkboxes — a bar appears to activate, deactivate or delete. Large batches run in the background.',
        'step9_title' => 'Need a refresher?',
        'step9_body'  => 'Reopen this tour anytime with the ? button. "Recent" in the avatar menu shows the last records you viewed.',
    ],
];
