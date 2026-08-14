<?php

return [
    // Header
    'title'           => 'Audit',
    'events_singular' => 'event recorded',
    'events_plural'   => 'events recorded',
    'visible_for'     => 'Visible to super and admin',
    'clear_filters'   => 'Clear filters',
    // See the note in the Spanish file.
    'unreadable'      => '(cannot be read with the current key)',

    // Filters
    'filter_module'    => 'Module',
    'filter_event'     => 'Event',
    'filter_user_id'   => 'User ID',
    'filter_user'      => 'User',
    'filter_record_id' => 'Record ID',
    'filter_from'      => 'From',
    'filter_to'        => 'To',

    // Table columns
    'col_date'   => 'Date',
    'col_event'  => 'Event',
    'col_module' => 'Module',
    'col_record' => 'Record #',
    'col_user'   => 'User',
    'col_url'    => 'Record URL',

    // Event labels
    'event_created'       => 'Created',
    'event_updated'       => 'Updated',
    'event_deleted'       => 'Deleted',
    'event_force_deleted' => 'Permanently deleted',
    'event_restored'      => 'Restored',
    'event_login'         => 'Sign in',
    'event_logout'        => 'Sign out',
    // A failed attempt stores the email it was tried with, never the password.
    // Several in a row from the same IP is what you need to be able to see.
    'event_login_failed'  => 'Failed attempt',
    'event_login_lockout' => 'Account throttled',
    // Right password, deactivated account: never actually got in.
    'event_login_blocked' => 'Access denied (account deactivated)',
    'event_purged'        => 'Purged',
    'event_export_queued' => 'Export requested',
    'event_terms_accepted' => 'Terms accepted',
    'event_personal_data_exported'     => 'Personal data downloaded',
    'event_account_deletion_requested' => 'Account deletion requested',
    'event_report_autosign_granted'    => 'Auto-signature enabled',
    'event_report_autosign_revoked'    => 'Auto-signature disabled',

    // Cells
    'system'       => 'System',
    'go_to_record' => 'Go to record',
    'view_detail'  => 'View detail',

    // Empty state
    'empty_title' => 'No events recorded',
    'empty_desc'  => 'No audited activity for the applied filters yet.',

    // Drawer detail
    'drawer_title'    => 'Event detail',
    'detail_id'       => 'ID',
    'detail_date'     => 'Date',
    'detail_event'    => 'Event',
    'detail_module'   => 'Module',
    'detail_model'    => 'Model',
    'detail_record_id' => 'Record ID',
    'detail_user'     => 'User',
    'detail_url'      => 'Record URL',
    'detail_ip'       => 'IP',
    'detail_ua'       => 'User-Agent',
    'old_values'      => 'Old values',
    'new_values'      => 'New values',
];
