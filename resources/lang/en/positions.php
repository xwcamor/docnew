<?php

return [
    'singular'                     => 'Position',
    'plural'                       => 'Positions',
    'record'                       => 'position',
    'records'                      => 'positions',
    'new'                          => 'Create position',
    'id'                           => 'No.',

    'index_title'                  => 'Positions',
    'index_subtitle'               => 'What each person does on site: technician, foreman, electrician.',
    'create_title'                 => 'Create position',
    'create_subtitle'              => 'A new position can be assigned to a person as soon as it is saved.',
    'edit_title'                   => 'Edit position',
    'delete_title'                 => 'Delete position',
    'show_title'                   => 'Position — Details',
    'trash_title'                  => 'Deleted positions',
    'empty_hint'                   => 'Create the first position: Technician, Supervisor, Mechanic, Electrician.',

    // Fields
    'code'                         => 'Position',
    'code_help'                    => 'The position as it is said on site: Technician, Supervisor, Mechanic, Electrician.',
    'country'                      => 'Country',
    'country_help'                 => 'The country it belongs to. Catalogues are kept per country because safety regulations differ.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive position is no longer offered when linking a person. Whoever already holds it keeps it.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'Workers holding it',
    'usage_list_title'             => 'Workers holding this position',
    'usage_list_none'              => 'Nobody holds it yet: it can be deleted without breaking anything.',

    // Messages
    'created'                      => 'Position created.',
    'saved'                        => 'Position updated.',
    'deactivated'                  => 'Position deactivated: it is no longer offered when linking a person to a company.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count person(s) hold this position. If it is no longer used, deactivate it — those who already hold it keep it.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'code_required'                => 'The "Position" field is required.',
    'code_unique'                  => 'Another position in that country already uses that name.',
    'is_active_required'           => 'The status field is required.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Positions',
        'step1_body'  => 'The position catalogue: what each person does on site is called here. Who approves a work plan is not decided here, but by the person\'s roles.',
        'step2_title' => 'Search',
        'step2_body'  => 'Type in the bar and the list filters itself. Nothing to press.',
        'step3_title' => 'Columns',
        'step3_body'  => 'Show or hide columns; the choice is remembered for next time.',
        'step4_title' => 'In use',
        'step4_body'  => 'The "Workers holding it" column says how many records depend on each row. With one or more it can no longer be deleted: it gets deactivated instead.',
        'step5_title' => 'Bulk actions',
        'step5_body'  => 'Tick rows to activate, deactivate or delete in bulk. Rows in use are skipped and you are told how many.',
    ],
];
