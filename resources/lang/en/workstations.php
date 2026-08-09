<?php

return [
    'singular'                     => 'Workstation',
    'plural'                       => 'Workstations',
    'record'                       => 'workstation',
    'records'                      => 'workstations',
    'new'                          => 'Create workstation',
    'id'                           => 'No.',

    'index_title'                  => 'Workstations',
    'index_subtitle'               => 'The workstations of each site. A work plan happens at one of them.',
    'create_title'                 => 'Create workstation',
    'create_subtitle'              => 'Pick the site first: the workstation belongs to it and is not offered at the others.',
    'edit_title'                   => 'Edit workstation',
    'delete_title'                 => 'Delete workstation',
    'show_title'                   => 'Workstation — Details',
    'trash_title'                  => 'Deleted workstations',
    'empty_hint'                   => 'Create the first workstation. Its site has to exist first.',

    // Fields
    'name'                         => 'Name',
    'name_help'                    => 'How the workstation is known on site. It reads next to its site when picked in a plan.',
    'work_location_id'             => 'Site',
    'work_location_help'           => 'The site the workstation belongs to. Pick it first: when registering a plan only the workstations of the chosen site are offered.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive workstation is no longer offered when registering a plan. Plans already naming it keep working.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'Plans using it',
    'usage_list_title'             => 'Work plans at this workstation',
    'usage_list_none'              => 'No plan uses it yet: it can be deleted without breaking anything.',
    'usage_list_more'              => 'And :count more.',
    'usage_kind_work_plan'         => 'Work plan',
    'usage_state_in_progress'      => 'In progress',
    'usage_state_done'             => 'Done',

    // Messages
    'created'                      => 'Workstation created.',
    'saved'                        => 'Workstation updated.',
    'deactivated'                  => 'Workstation deactivated: it is no longer offered when registering new plans.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count work plan(s) were done at this workstation, and a signed plan is evidence. Deactivate it so it stops being offered.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'name_required'                => 'The "Name" field is required.',
    'name_unique'                  => 'Another workstation at that site already uses that name.',
    'is_active_required'           => 'The status field is required.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Workstations',
        'step1_body'  => 'The workstation catalogue. Each one belongs to a site, and the plan selector only shows the ones of the chosen site.',
        'step2_title' => 'Search',
        'step2_body'  => 'Type in the bar and the list filters itself. Nothing to press.',
        'step3_title' => 'Columns',
        'step3_body'  => 'Show or hide columns; the choice is remembered for next time.',
        'step4_title' => 'In use',
        'step4_body'  => 'The "Plans using it" column says how many records depend on each row. With one or more it can no longer be deleted: it gets deactivated instead.',
        'step5_title' => 'Bulk actions',
        'step5_body'  => 'Tick rows to activate, deactivate or delete in bulk. Rows in use are skipped and you are told how many.',
    ],
];
