<?php

return [
    // Modal & sections
    'title'                => 'Import records',
    'file'                 => 'File',
    'download_template'    => 'Download template',
    'mode'                 => 'Import mode',
    'preview_result'       => 'Preview result',
    'errors'               => 'Errors',
    'preview_changes'      => 'Changes preview',

    // Dropzone
    'drag_file_strong'     => 'Drag your file',
    'drag_or_click'        => 'or click to select',
    'formats_hint'         => 'Formats: .xlsx, .xls, .csv · Max 10 MB',

    // Modes
    'mode_update_or_create'      => 'Update or create',
    'mode_update_or_create_hint' => 'If a record with the same name already exists, it will be updated. Otherwise, it will be created.',
    'mode_create_only'           => 'Create only',
    'mode_create_only_hint'      => 'Creates only new records. Skips existing names.',

    // Stats
    'stat_create'          => 'Create',
    'stat_update'          => 'Update',
    'stat_skip'            => 'Skip',
    'stat_errors'          => 'Errors',

    // Action tags
    'action_create'        => 'Create',
    'action_update'        => 'Update',
    'action_skip'          => 'Skip',

    // Alerts
    'no_changes'           => 'No changes to apply',
    'no_changes_desc'      => 'All records in the file already exist identically in the database.',
    'rows_with_problems'   => ':count row has problems',
    'rows_with_problems_plural' => ':count rows have problems',
    'rows_with_problems_desc'   => 'These rows will be skipped on confirmation. The rest will be imported normally.',

    // Errors
    'process_failed'             => 'Could not process the file. Please check the format.',
    'err_unique_violation'       => 'The file contains values that already exist in another record (duplicate of a unique field). Review the data and try again.',
    'err_not_null_violation'     => 'A required field is missing in one of the rows. Check that all required columns are filled in.',
    'err_foreign_key_violation'  => 'A row references a related record that does not exist (e.g. invalid region or locale).',

    // Table columns
    'col_row'              => 'Row',
    'col_name'             => 'Name',
    'col_active'           => 'Active',
    'col_action'           => 'Action',
    'col_value'            => 'Value',
    'col_error'            => 'Error',

    // Buttons
    'preview_import'       => 'Preview import',
    'confirm_import'       => 'Confirm import (:count)',

    // Row-level error messages
    'err_name_required'    => 'The name is required.',
    'err_cod_required'      => 'The code (Tax ID) is required.',
    'err_code_too_long'     => 'The code exceeds 40 characters.',
    'err_code_duplicate'    => 'The code ":value" already exists on another record.',
    'err_name_too_long'    => 'The name exceeds 255 characters.',
    'err_duplicate_in_file' => 'Duplicate: already appears on row :row of this file.',
    'err_fk_not_found'     => '":value" was not found in the :field catalog. Check the code/name or create it before importing.',
    'err_not_numeric'      => ':field must be numeric (got ":value").',
    'err_bad_year'         => 'Invalid manufacture year: ":value" (use a year between 1900 and now).',
    'err_bad_paper'        => 'Invalid paper type: ":value" (use kraft or upgraded).',
    'err_bad_phases'       => 'Invalid number of phases: ":value" (use 1, 2 or 3).',
    'err_bad_date'         => 'Invalid date: ":value" (use the YYYY-MM-DD format).',
    'err_country_required'  => 'Country (country_iso) is required to create a customer.',
    'err_country_unknown'   => 'Country ISO code ":value" does not match any active country.',
    'err_cod_country_dup'   => 'The code ":value" already exists for another customer in that country.',

    // Template
    'template_filename'         => 'regions-template.xlsx',
    'template_author'           => 'System',
    'template_is_active_help'   => 'Accepted values: 1, 0, true, false, yes, no, active, inactive. Empty = active by default.',
    'template_sample_1'         => 'South America',
    'template_sample_2'         => 'Eastern Europe',
];
