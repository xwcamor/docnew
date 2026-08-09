<?php

return [
    'singular'                     => 'Site',
    'plural'                       => 'Sites',
    'record'                       => 'site',
    'records'                      => 'sites',
    'new'                          => 'Create site',
    'id'                           => 'No.',

    'index_title'                  => 'Sites',
    'index_subtitle'               => 'Where the work happens: every work plan belongs to a site.',
    'create_title'                 => 'Create site',
    'create_subtitle'              => 'A new site can be picked in a work plan as soon as it is saved.',
    'edit_title'                   => 'Edit site',
    'delete_title'                 => 'Delete site',
    'show_title'                   => 'Site — Details',
    'trash_title'                  => 'Deleted sites',
    'empty_hint'                   => 'Create the first site: without one, no work plan can be registered.',

    // Fields
    'name'                         => 'Name',
    'name_help'                    => 'How the site is known on site (Lurín, Talara). It is what you read when picking it in a plan.',
    'country'                      => 'Country',
    'country_help'                 => 'The country it belongs to. Catalogues are kept per country because safety regulations differ.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive site is no longer offered when registering a plan. Plans already naming it keep working.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'Workstations and plans using it',
    'usage_list_title'             => 'What hangs off this site',
    'usage_list_none'              => 'Nothing hangs off this site yet: it can be deleted without breaking anything.',
    'usage_list_more'              => 'And :count more.',
    'usage_kind_workstation'       => 'Workstation',
    'usage_kind_work_plan'         => 'Work plan',
    'usage_state_active'           => 'Active',
    'usage_state_inactive'         => 'Inactive',
    'usage_state_in_progress'      => 'In progress',
    'usage_state_done'             => 'Done',

    // Messages
    'created'                      => 'Site created.',
    'saved'                        => 'Site updated.',
    'deactivated'                  => 'Site deactivated: it is no longer offered when registering new plans.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count workstation(s) or work plan(s) hang off this site. If it is no longer used, deactivate it — what is already registered stays in place.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'name_required'                => 'The "Name" field is required.',
    'name_unique'                  => 'Another site in that country already uses that name.',
    'is_active_required'           => 'The status field is required.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Sites',
        'step1_body'  => 'The site catalogue. A work plan always belongs to one, and workstations hang off it.',
        'step2_title' => 'Search',
        'step2_body'  => 'Type in the bar and the list filters itself. Nothing to press.',
        'step3_title' => 'Columns',
        'step3_body'  => 'Show or hide columns; the choice is remembered for next time.',
        'step4_title' => 'In use',
        'step4_body'  => 'The "Workstations and plans using it" column says how many records depend on each row. With one or more it can no longer be deleted: it gets deactivated instead.',
        'step5_title' => 'Bulk actions',
        'step5_body'  => 'Tick rows to activate, deactivate or delete in bulk. Rows in use are skipped and you are told how many.',
    ],
];
