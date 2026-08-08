<?php

return [
    'singular'                     => 'Nationality',
    'plural'                       => 'Nationalities',
    'record'                       => 'nationality',
    'records'                      => 'nationalities',
    'new'                          => 'Create nationality',
    'id'                           => 'No.',

    'index_title'                  => 'Nationalities',
    'index_subtitle'               => 'The nationality written down on each person record.',
    'create_title'                 => 'Create nationality',
    'create_subtitle'              => 'A new nationality can be picked on a person record as soon as it is saved.',
    'edit_title'                   => 'Edit nationality',
    'delete_title'                 => 'Delete nationality',
    'show_title'                   => 'Nationality — Details',
    'trash_title'                  => 'Deleted nationalities',
    'empty_hint'                   => 'The table is empty. Create the first nationality so it can be written on the records.',

    // Fields
    'code'                         => 'Nationality',
    'code_help'                    => 'How it reads on the person record (Peruvian, Venezuelan). It is the text you pick, not an internal code.',
    'country'                      => 'Country',
    'country_help'                 => 'The country it belongs to. Catalogues are kept per country because safety regulations differ.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive nationality is no longer offered on a person record. Records that already have it keep it.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'People with this nationality',
    'usage_list_title'             => 'People with this nationality',
    'usage_list_none'              => 'Nobody has it yet: it can be deleted without breaking anything.',

    // Messages
    'created'                      => 'Nationality created.',
    'saved'                        => 'Nationality updated.',
    'deactivated'                  => 'Nationality deactivated: it is no longer offered on a person record.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count person(s) have this nationality. If it is no longer used, deactivate it — records that already have it keep it.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'code_required'                => 'The "Nationality" field is required.',
    'code_unique'                  => 'Another nationality in that country already uses that name.',
    'is_active_required'           => 'The status field is required.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Nationalities',
        'step1_body'  => 'The nationality catalogue. It shows on every person record; the table starts empty and is filled here.',
        'step2_title' => 'Search',
        'step2_body'  => 'Type in the bar and the list filters itself. Nothing to press.',
        'step3_title' => 'Columns',
        'step3_body'  => 'Show or hide columns; the choice is remembered for next time.',
        'step4_title' => 'In use',
        'step4_body'  => 'The "People with this nationality" column says how many records depend on each row. With one or more it can no longer be deleted: it gets deactivated instead.',
        'step5_title' => 'Bulk actions',
        'step5_body'  => 'Tick rows to activate, deactivate or delete in bulk. Rows in use are skipped and you are told how many.',
    ],
];
