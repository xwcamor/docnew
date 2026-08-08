<?php

return [
    'singular'                     => 'DocumentType',
    'plural'                       => 'DocumentTypes',
    'record'                       => 'documentType',
    'records'                      => 'document_types',
    'new'                          => 'Create documentType',
    'id'                           => 'No.',

    'index_title'                  => 'DocumentTypes',
    'index_subtitle'               => 'The documentType written down on each person record.',
    'create_title'                 => 'Create documentType',
    'create_subtitle'              => 'A new documentType can be picked on a person record as soon as it is saved.',
    'edit_title'                   => 'Edit documentType',
    'delete_title'                 => 'Delete documentType',
    'show_title'                   => 'DocumentType — Details',
    'trash_title'                  => 'Deleted document_types',
    'empty_hint'                   => 'The table is empty. Create the first documentType so it can be written on the records.',

    // Fields
    'name' => 'Full name',
    'name_help' => 'What the document is called: “National Identity Document”, “Foreigner ID Card”. The abbreviation goes above.',
    'min_length' => 'Minimum length',
    'max_length' => 'Maximum length',
    'length_help' => 'How many characters the number has. This helps when registering a person, it is not what the search uses: the worker search matches the number exactly. Leave blank if the document has no fixed length.',
    'code'                         => 'DocumentType',
    'code_help'                    => 'How it reads on the person record (Peruvian, Venezuelan). It is the text you pick, not an internal code.',
    'country'                      => 'Country',
    'country_help'                 => 'The country it belongs to. Catalogues are kept per country because safety regulations differ.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive documentType is no longer offered on a person record. Records that already have it keep it.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'People with this documentType',
    'usage_list_title'             => 'People with this documentType',
    'usage_list_none'              => 'Nobody has it yet: it can be deleted without breaking anything.',

    // Messages
    'created'                      => 'DocumentType created.',
    'saved'                        => 'DocumentType updated.',
    'deactivated'                  => 'DocumentType deactivated: it is no longer offered on a person record.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count person(s) have this documentType. If it is no longer used, deactivate it — records that already have it keep it.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'code_required'                => 'The "DocumentType" field is required.',
    'code_unique'                  => 'Another documentType in that country already uses that name.',
    'is_active_required'           => 'The status field is required.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'DocumentTypes',
        'step1_body'  => 'The documentType catalogue. It shows on every person record; the table starts empty and is filled here.',
        'step2_title' => 'Search',
        'step2_body'  => 'Type in the bar and the list filters itself. Nothing to press.',
        'step3_title' => 'Columns',
        'step3_body'  => 'Show or hide columns; the choice is remembered for next time.',
        'step4_title' => 'In use',
        'step4_body'  => 'The "People with this documentType" column says how many records depend on each row. With one or more it can no longer be deleted: it gets deactivated instead.',
        'step5_title' => 'Bulk actions',
        'step5_body'  => 'Tick rows to activate, deactivate or delete in bulk. Rows in use are skipped and you are told how many.',
    ],
];
