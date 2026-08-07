<?php

return [
    // Titles
    'singular'        => 'Country',
    'plural'          => 'Countries',
    'new'             => 'New country',
    'records'         => 'countries',
    'record'          => 'country',
    'empty_hint'      => 'Create the first country or import a batch from Excel to get started.',
    'name_placeholder'=> 'E.g.: Peru',
    'form_create_hint'=> 'Fill in the data to create a new country.',
    'delete_hint'     => 'The country information will be removed.',
    'delete_about'    => 'You are about to delete the country :name.',
    'restore_hint'    => 'It will be available again in the main list.',

    // Columns
    'index_title'     => 'Countries List',
    'create_title'    => 'Country - Create',
    'show_title'      => 'Country - Details',
    'edit_title'      => 'Country - Edit',
    'delete_title'    => 'Country - Delete',
    'edit_all_title'  => 'Country - Edit All',
    'id'              => 'No.',
    'name'            => 'Name',
    'iso_code'        => 'ISO code',
    'currency'        => 'Currency',
    'timezone'        => 'Timezone',
    'region'          => 'Region',
    'default_locale'  => 'Default locale',
    'is_active'       => 'Status',

    'iso_code_placeholder' => 'PE',
    'currency_placeholder' => 'PEN',
    'timezone_placeholder' => 'America/Lima',
    'region_placeholder'   => 'Select region',
    'default_locale_placeholder' => 'Select locale',

    // Form field helpers
    'name_help'           => 'Country name as it will appear in lists and reports.',
    'iso_code_help'       => 'ISO 3166-1 alpha-2 two-letter uppercase code (PE, BR, US).',
    'currency_help'       => 'Official currency in ISO 4217 alpha-3 format (PEN, USD, EUR).',
    'region_help'         => 'Geographic region the country belongs to. Only active regions are listed.',
    'default_locale_help' => 'Locale used by default for users from this country (language and regional format).',
    'timezone_help'       => 'Primary timezone of the country in IANA format (e.g. America/Lima).',
    'is_active_help'      => 'If inactive, it cannot be assigned to new users or workspaces.',

    'table_headers' => [
        'editable_name'           => 'Name (editable)',
        'editable_iso_code'       => 'ISO (editable)',
        'editable_currency'       => 'Currency (editable)',
        'editable_timezone'       => 'Timezone (editable)',
        'editable_region'         => 'Region (editable)',
        'editable_default_locale' => 'Locale (editable)',
        'editable_status'         => 'Status (editable)',
    ],

    'export_filename' => 'countries_export',
    'import_template_filename' => 'countries-template.xlsx',
    'export_title'    => 'Countries Report',
    'export_limit_exceeded'    => 'The :format export exceeds the limit (:count rows vs :limit max). Use CSV for large datasets (no limit).',
    'export_format_limit_hint' => 'Maximum :limit rows for this format. Use CSV for large datasets.',
    'export_no_limit_hint'     => 'No limit — recommended for large datasets.',

    'name_required'           => 'The name field is required.',
    'name_unique'             => 'This country already exists.',
    'name_duplicate_in_batch' => 'Duplicate name within the same batch.',

    'iso_code_required'       => 'The ISO code is required.',
    'iso_code_regex'          => 'Invalid format. Must be ISO 3166-1 alpha-2 (PE, BR, US).',
    'iso_code_unique'         => 'This ISO code is already in use by another country.',

    'currency_required'       => 'The currency is required.',
    'currency_regex'          => 'Invalid format. Must be ISO 4217 alpha-3 (PEN, USD).',

    'timezone_required'       => 'The timezone is required.',
    'timezone_invalid'        => 'Invalid timezone. Use IANA format (America/Lima).',

    'region_required'         => 'The region is required.',
    'region_invalid'          => 'The selected region does not exist or is inactive.',

    'default_locale_required' => 'The default locale is required.',
    'default_locale_invalid'  => 'The selected locale does not exist or is inactive.',

    'is_active_required'      => 'The status field is required.',

    'deleted_description_required' => 'The deletion reason is required.',
    'deleted_description_min'      => 'The deletion reason must be at least 3 characters.',
    'deleted_description_max'      => 'The deletion reason must not exceed 1000 characters.',

    'edit_all_subtitle'   => 'Edit several fields across many countries at once. Click "Save all" to commit, "Cancel" to discard.',
    'edit_all_changes'    => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all'   => 'Save all',
    'edit_all_discard'    => 'Discard changes',
    'edit_all_no_results' => 'No countries match the filter.',

    'tour' => [
        'step1_title' => 'Welcome to Countries',
        'step1_body'  => 'Master catalog of countries with ISO code, currency, timezone and region. We will show the key points in under a minute.',
        'step2_title' => 'Filters',
        'step2_body'  => 'Search and filter by name, ISO code, currency, region, status and ID. Active filters appear as chips above.',
        'step3_title' => 'Saved views',
        'step3_body'  => 'Save your favorite combination of filters + columns + sort. Each user has their own.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Show/hide columns; your choice is remembered. Required columns cannot be hidden.',
        'step5_title' => 'Export & Import',
        'step5_body'  => 'Export to Excel/PDF/Word in the background — you will be notified when ready. Import from Excel/CSV with preview.',
        'step6_title' => 'Bulk edit',
        'step6_body'  => '"Edit all" lets you modify many countries together. Then commit all changes in a single save.',
        'step7_title' => 'Favorites ★',
        'step7_body'  => 'The star ★ marks a row as a favorite. Favorites always show at the top of the list; each user has their own.',
        'step8_title' => 'Bulk operations',
        'step8_body'  => 'Select rows with the checkboxes — a bar appears to activate, deactivate, deactivate, delete or restore.',
        'step9_title' => 'Need a refresher?',
        'step9_body'  => 'Reopen this tour anytime with the ? button. You also have "Recent" in the avatar menu.',
        'step10_title' => 'Trash',
        'step10_body'  => 'Open the trash to see deleted items (super only). From here you can restore or permanently delete.',
        'step11_title' => 'Audit log',
        'step11_body'  => 'Full change history for this module: who, what and when. Useful for debugging or audits.',
        'step12_title' => 'Create new',
        'step12_body'  => 'Primary button to create a new record. Keyboard shortcut: Ctrl+N.',
    ],
];
