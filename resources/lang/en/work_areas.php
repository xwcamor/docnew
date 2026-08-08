<?php

return [
    'singular'                     => 'Area',
    'plural'                       => 'Areas',
    'record'                       => 'area',
    'records'                      => 'areas',
    'new'                          => 'Create area',
    'id'                           => 'No.',

    'index_title'                  => 'Areas',
    'index_subtitle'               => 'The part of the site where the work happens: it is written down in the plan.',
    'create_title'                 => 'Create area',
    'create_subtitle'              => 'A new area can be picked in a work plan as soon as it is saved.',
    'edit_title'                   => 'Edit area',
    'delete_title'                 => 'Delete area',
    'show_title'                   => 'Area — Details',
    'trash_title'                  => 'Deleted areas',
    'empty_hint'                   => 'Create the first area.',

    // Fields
    'name'                         => 'Name',
    'name_help'                    => 'How the area is known on site (Plant, Warehouse, Switchyard).',
    'country'                      => 'Country',
    'country_help'                 => 'The country it belongs to. Catalogues are kept per country because safety regulations differ.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive area is no longer offered when registering a plan. Plans already naming it keep working.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'Plans using it',
    'usage_list_title'             => 'Work plans in this area',
    'usage_list_none'              => 'No plan uses it yet: it can be deleted without breaking anything.',

    // Messages
    'created'                      => 'Area created.',
    'saved'                        => 'Area updated.',
    'deactivated'                  => 'Area deactivated: it is no longer offered when registering new plans.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count work plan(s) were done in this area, and a signed plan is evidence. Deactivate it so it stops being offered.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'name_required'                => 'The "Name" field is required.',
    'name_unique'                  => 'Another area in that country already uses that name.',
    'is_active_required'           => 'The status field is required.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Areas',
        'step1_body'  => 'The area catalogue. It is the part of the site where the work happens, and it is written down in every plan.',
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
