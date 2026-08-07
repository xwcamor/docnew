<?php

return [
    // Titles
    'singular'        => 'Region',
    'plural'          => 'Regions',
    'new'             => 'New region',
    'records'         => 'regions',
    'record'          => 'region',
    'empty_hint'      => 'Create the first region or bulk-import from Excel to get started.',
    'name_placeholder'=> 'E.g.: South America',
    'form_create_hint'=> 'Fill in the fields to create a new region.',
    'delete_hint'     => 'The region information will be removed.',
    'delete_about'    => 'You are about to delete the region :name.',
    'restore_hint'    => 'It will be available again in the main list.',

    // Columns
    'index_title'     => 'Regions List',
    'create_title'    => 'Region - Create',
    'show_title'      => 'Region - Information',
    'edit_title'      => 'Region - Edit',
    'delete_title'    => 'Region - Delete',
    'edit_all_title'  => 'Region - Edit All',
    'id'              => 'No.',
    'name'            => 'Name',
    'name_help'       => 'Name of the geographic region as it will appear in country selectors.',
    'is_active'       => 'Status',
    'is_active_help'  => 'If inactive, the region cannot be assigned to new countries.',

    // Table headers for live edit
    'table_headers' => [
        'editable_name' => 'Name (editable)',
        'editable_status' => 'Status (editable)',
    ],

    // Export
    'export_filename' => 'regions_export',
    'import_template_filename' => 'regions-template.xlsx',
    'export_title'    => 'Regions Report',
    'export_limit_exceeded' => 'Export to :format exceeds the limit (:count rows vs :limit max). Use CSV for large datasets (no limit).',
    'export_format_limit_hint' => 'Max :limit rows for this format. Use CSV for large datasets.',
    'export_no_limit_hint'  => 'No limit — recommended for large datasets.',

    // Validation messages
    // name
    'name_required'           => 'The name field is required.',
    'name_unique'             => 'This region already exists.',
    'name_duplicate_in_batch' => 'Duplicate name within the same batch.',

    // is_active
    'is_active_required' => 'The status field is required.',

    // deletion
    'deleted_description_required' => 'The deletion reason is required.',
    'deleted_description_min'      => 'The deletion reason must be at least 3 characters.',
    'deleted_description_max'      => 'The deletion reason cannot exceed 1000 characters.',

    // Edit All — bulk inline editing
    'edit_all_subtitle'  => 'Edit name and status for many regions at once. Click "Save all" to commit, "Cancel" to discard.',
    'edit_all_changes'   => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all'  => 'Save all',
    'edit_all_discard'   => 'Discard changes',
    'edit_all_no_results' => 'No regions match the filter.',

    // Onboarding tour copy (4 steps)
    'tour' => [
        'step1_title' => 'Welcome to Regions',
        'step1_body'  => 'This is the master template for all your modules. Let\'s do a quick 4-step tour.',
        'step2_title' => 'Filters',
        'step2_body'  => 'Search and filter by name, status, dates and ID. Active filters appear as removable chips above the table.',
        'step3_title' => 'Saved Views',
        'step3_body'  => 'Save your favorite filter + columns + sort combos and apply them again with one click. Each user has their own.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Show/hide columns and persist your choice. The "Required" ones can\'t be hidden.',
        'step5_title' => 'Export & Import',
        'step5_body'  => 'Export to Excel/PDF/Word in the background — you\'ll get a notification when ready. Import from Excel/CSV with a preview before commit.',
        'step6_title' => 'Edit many at once',
        'step6_body'  => '"Edit all" lets you update name and status for multiple records in one go. Save them all in a single batch.',
        'step7_title' => 'Favorites ★',
        'step7_body'  => 'The star ★ marks a row as a favorite. Favorites always show at the top of the list; each user has their own.',
        'step8_title' => 'Bulk operations',
        'step8_body'  => 'Select rows with the checkboxes — a toolbar appears for activate, deactivate, delete or restore. Works for hundreds of rows; large batches run in the background.',
        'step9_title' => 'Need a refresher?',
        'step9_body'  => 'Reopen this tour anytime with the ? button up here. You can also access "Recents" from your avatar menu — your last viewed items across all modules.',
        'step10_title' => 'Trash',
        'step10_body'  => 'Open the trash to see deleted items (super only). From here you can restore or permanently delete.',
        'step11_title' => 'Audit log',
        'step11_body'  => 'Full change history for this module: who, what and when. Useful for debugging or audits.',
        'step12_title' => 'Create new',
        'step12_body'  => 'Primary button to create a new record. Keyboard shortcut: Ctrl+N.',
    ],
];
