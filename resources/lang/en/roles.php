<?php

return [
    'singular'              => 'Role',
    'record'                => 'role',
    'plural'                => 'Roles',
    'new'                   => 'New role',
    'edit_title'            => 'Edit role',
    'index_subtitle'        => 'Define what each kind of user can do in your workspace.',
    'form_create_hint'      => 'Create a role with specific permissions for your team.',

    'name'                  => 'Name',
    'name_help'             => 'Role name as it will appear when assigning it to a user (e.g. "Sales", "Auditor").',
    'name_placeholder'      => 'E.g.: Sales',
    'description'           => 'Description',
    'description_help'      => 'Optional notes for your team. Explains what kind of user would have this role.',
    'description_placeholder' => 'What this role is for and who it is assigned to.',
    'tenant'                => 'Workspace',
    'tenant_placeholder'    => 'Workspace it belongs to',
    'tenant_help'           => 'Workspace this role belongs to. Leave empty to create a global role available to all (super only).',
    'tenant_hint'           => 'Leave empty to create a global role (super only).',
    'permissions'           => 'Permissions',
    'permissions_count'     => 'Permissions',
    'permissions_hint'      => 'Check the permissions this role will have. Grouped by module.',
    'no_permissions_available' => 'No permissions available to assign.',
    'permissions_required'  => 'The role must have at least one permission.',
    // Humanized permission actions (module name comes from the sidebar).
    'perm_action' => [
        'view'    => 'View list',
        'show'    => 'View detail',
        'create'  => 'Create',
        'edit'    => 'Edit',
        'delete'  => 'Delete',
        'export'  => 'Export',
        'import'  => 'Import',
        'samples' => 'Load samples',
    ],
    // Permission modules NOT in the sidebar (the rest are reused from it).
    'perm_module' => [
        'comments'        => 'Sample comments',
        'diagnosis_notes' => 'Diagnostician note',
    ],
    'users_count'           => 'Users',

    'confirm_delete'        => 'Delete this role?',
    'protected'             => 'Protected',
    'tag_system'            => 'System',
    'tag_global'            => 'Global',
    'no_permissions'        => 'This role has no permissions assigned.',

    // Index/Filters
    'search_placeholder'    => 'Search by name…',
    'is_active'             => 'Status',
    'scope'                 => 'Scope',
    'confirm_duplicate'     => 'Duplicate this role with its permissions?',

    // Delete
    'delete_title'          => 'Delete role',
    'delete_warning_title'  => 'Delete this role?',
    'delete_warning_desc'   => 'The role moves to trash. You can restore it within 30 days. Users with this role lose it immediately.',
    'delete_blocked_title'  => 'Cannot delete',
    'delete_blocked_users'  => 'Cannot delete a role with assigned users.',
    'delete_blocked_users_count' => 'This role is assigned to :count user(s). Reassign them first.',
    'bulk_delete_about'     => 'About to delete :count roles.',

    // Trash
    'trash_subtitle'        => 'Deleted roles (recoverable for 30 days).',
    'trash_super_warning'   => 'As super you can permanently delete. This action CANNOT be reverted.',
    'force_delete_warning'  => 'The role is wiped from the DB along with its assignments. NOT recoverable.',

    // Onboarding tour (6 steps: filters, saved-views, columns, favorites, bulk, system_logs)
    'tour' => [
        'step2_title' => 'Filters',
        'step2_body'  => 'Search and filter roles by name, status, scope and dates. Active filters show as chips.',
        'step3_title' => 'Saved views',
        'step3_body'  => 'Save your favorite filter + columns + sort combo and reapply with one click.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Show/hide columns; your choice persists. Required ones cannot be hidden.',
        'step5_title' => 'Export & Import',
        'step5_body'  => 'Export the list to CSV. Import roles from a file (requires a plan with bulk operations).',
        'step6_title' => 'Edit all',
        'step6_body'  => 'Edit name, description and status of multiple roles in one screen and save them all at once.',
        'step7_title' => 'Favorites ★',
        'step7_body'  => 'The star ★ marks a role as favorite. Favorites always show at the top of the list.',
        'step8_title' => 'Bulk operations',
        'step8_body'  => 'Select rows with checkboxes to activate, deactivate or delete multiple roles at once.',
        'step_audit_title' => 'System Logs',
        'step_audit_body'  => 'Change history of roles: who, what and when. Useful for audits.',
    ],
    'export_title'        => 'Roles Report',
    'edit_all_title'      => 'Role - Edit All',
    'edit_all_subtitle'   => 'Edit name, description and status of multiple roles at once. System roles are not editable.',
    'edit_all_changes'    => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all'   => 'Save all',
    'edit_all_discard'    => 'Discard changes',
    'edit_all_no_results' => 'No roles match the filter.',
    'table_headers'       => [
        'editable_name'        => 'Name (editable)',
        'editable_description' => 'Description (editable)',
        'editable_status'      => 'Status (editable)',
    ],
];