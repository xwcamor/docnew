<?php

return [
    'singular' => 'Approver role',
    'plural'   => 'Approver roles',
    'record'   => 'approver role',
    'records'  => 'approver roles',
    'new'      => 'Create approver role',
    'id'       => 'No.',

    'index_title'      => 'Approver roles',
    'index_subtitle'   => 'Who can sign off a work plan.',
    'create_title'     => 'Create approver role',
    'create_subtitle'  => 'A new role can be used in flow rules as soon as it is saved.',
    'edit_title'       => 'Edit approver role',
    'delete_title'     => 'Delete approver role',
    'show_title'       => 'Approver role — Details',
    'trash_title'      => 'Approver roles bin',
    'form_create_hint' => 'A new role can be used in flow rules as soon as it is saved.',
    'empty_hint'       => 'Create the first role or import the catalogue from Excel.',

    // Fields
    'code'            => 'Code',
    'code_help'       => 'The key rules use to name the role: lowercase, no spaces or accents (e.g. site_manager). It is fixed up as you type.',
    'code_placeholder' => 'site_manager',
    'name_es'         => 'Spanish name',
    'name_es_help'    => 'How it reads when the app is in Spanish.',
    'name_en'         => 'English name',
    'name_en_help'    => 'How it reads when the app is in English.',
    'sort_order'      => 'Order',
    'sort_order_help' => 'Where it shows up when picking a role. Not the flow level — that belongs to each rule.',
    'is_active'       => 'Status',
    'is_active_help'  => 'An inactive role is no longer offered when creating rules. Rules already using it keep working.',
    'filter_name'     => 'Search',
    'rules_count'     => 'Rules using it',
    'system'          => 'Built in',
    'system_hint'     => 'Ships with the system. It can be renamed, but its code never changes and it is never deleted: seeded rules and the data migration name it that way.',

    // Messages
    'created'     => 'Approver role created.',
    'saved'       => 'Approver role updated.',
    'deleted'     => 'Approver role deleted.',
    'deactivated' => 'Approver role deactivated: it will no longer be offered on new rules.',

    'delete_about'                 => 'You are about to delete ":name". It will go to the bin.',
    'deleted_description_required' => 'State the reason for the deletion.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks deletion
    'in_use_cannot_delete'    => 'Cannot delete: :count flow rule(s) use it. If you no longer want it offered, deactivate it — the rules naming it keep working.',
    'system_cannot_delete'    => 'Cannot delete ":code": it is one of the roles that ships with the system, and seeded rules name it by that code.',
    'deactivate_instead'      => 'Deactivate instead',
    'bulk_skipped_protected'  => ':count not deleted (in use or built in)',
    'bulk_all_protected'      => 'None could be deleted: all :count selected are in use or built in.',
    'rules_using_it'          => 'Flow rules signed by this role',
    'rules_none'              => 'No rule uses it yet: it can be deleted without breaking anything.',
    'all_work_types'          => 'All types',

    // Export / import
    'export_filename'          => 'approver_roles',
    'import_template_filename' => 'approver-roles-template.xlsx',
    'export_title'             => 'Approver role catalogue',
    'export_limit_exceeded'    => 'The :format export exceeds the limit (:count rows vs :limit). Use CSV for large volumes.',
    'export_format_limit_hint' => 'Up to :limit rows in this format. Use CSV if you need more.',
    'export_no_limit_hint'     => 'No limit — recommended for large volumes.',

    // Validation
    'code_required'        => 'The code is required.',
    'code_unique'          => 'A role with this code already exists.',
    'code_regex'           => 'The code only takes lowercase letters, digits and underscores (e.g. site_manager).',
    'name_es_required'     => 'The Spanish name is required.',
    'name_en_required'     => 'The English name is required.',
    'is_active_required'   => 'The status field is required.',
    'import_super_blocked' => 'A super with no workspace cannot import: it would write over the global catalogue every workspace shares.',
    'import_code_required' => 'The code is missing, and that is what identifies the role.',
    'import_names_required'=> 'A new role needs both a Spanish and an English name.',

    // Edit all
    'edit_all_title'      => 'Approver roles — Edit all',
    'edit_all_subtitle'   => 'Change the Spanish name and the status of several roles at once. The code is not editable here: it is a key.',
    'edit_all_changes'    => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all'   => 'Save all',
    'edit_all_discard'    => 'Discard changes',
    'edit_all_duplicate_names' => 'There are repeated names: two roles sharing a Spanish name are indistinguishable when picking who signs.',
    'edit_all_no_results' => 'No role matches the filter.',

    'table_headers' => [
        'editable_name'   => 'Spanish name (editable)',
        'editable_status' => 'Status (editable)',
    ],

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Approver roles',
        'step1_body'  => 'The catalogue of who can sign a plan. These used to be three names hard-coded; adding a "Client" or a "Site manager" is now a row.',
        'step2_title' => 'Search',
        'step2_body'  => 'Search looks at the code and both names at once, because nobody should have to remember which column it was in.',
        'step3_title' => 'Saved views',
        'step3_body'  => 'Save your combination of filters, columns and sorting, and come back to it with one click.',
        'step4_title' => 'Columns',
        'step4_body'  => 'Show or hide columns; your choice is remembered for next time.',
        'step5_title' => 'Export and import',
        'step5_body'  => 'Exports are prepared in the background and notify you when ready. Imports show a preview before you confirm.',
        'step6_title' => 'Edit many at once',
        'step6_body'  => 'Change the name and status of several roles and confirm everything in a single save.',
        'step7_title' => 'In use',
        'step7_body'  => 'The "Rules using it" column says how many flow rules sign with that role. With one or more, the role can no longer be deleted — you deactivate it.',
        'step8_title' => 'Bulk actions',
        'step8_body'  => 'Tick rows to activate, deactivate or delete in bulk. Roles in use and built-in ones are skipped, and you are told how many.',
        'step9_title' => 'Need a refresher?',
        'step9_body'  => 'Reopen this tour any time from the tools menu.',
    ],
];
