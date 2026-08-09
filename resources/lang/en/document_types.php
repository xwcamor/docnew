<?php

return [
    'singular'                     => 'Document type',
    'plural'                       => 'Document types',
    'record'                       => 'document type',
    'records'                      => 'document types',
    'new'                          => 'Create document type',
    'id'                           => 'No.',

    'index_title'                  => 'Document types',
    'index_subtitle'               => 'Which ID each person carries: national ID, foreigner card, passport, temporary permit.',
    'create_title'                 => 'Create document type',
    'create_subtitle'              => 'A new document type can be picked on a person record as soon as it is saved.',
    'edit_title'                   => 'Edit document type',
    'delete_title'                 => 'Delete document type',
    'show_title'                   => 'Document type — Details',
    'trash_title'                  => 'Deleted document types',
    'empty_hint'                   => 'The table is empty. Create the first document type so it can be written on the records.',

    // Fields
    'code'                         => 'Abbreviation',
    'code_help'                    => 'The abbreviation that reads on the person record: DNI, CE, PTP, PASAPORTE. It is the text that stays written on the record.',
    'name'                         => 'Full name',
    'name_help'                    => 'What the document is called: “National Identity Document”, “Foreigner ID Card”. The abbreviation goes above.',
    'min_length'                   => 'Minimum length',
    'max_length'                   => 'Maximum length',
    'length'                       => 'Length',
    'length_help'                  => 'How many characters the number has. This helps when registering a person, it is not what the search uses: the worker search matches the number exactly. Leave blank if the document has no fixed length.',
    'length_range'                 => ':min to :max characters',
    'length_min_only'              => 'from :min characters',
    'length_max_only'              => 'up to :max characters',
    'length_none'                  => 'No fixed length',
    'country'                      => 'Country',
    'country_help'                 => 'The country it belongs to. Catalogues are kept per country because the document is not the same everywhere: the Peruvian DNI has 8 digits and the Chilean one is not even called that.',
    'is_active'                    => 'Status',
    'is_active_help'               => 'An inactive document type is no longer offered on a person record. Records that already have it keep it.',
    'filter_name'                  => 'Search',
    'usage_count'                  => 'People with this document',
    'usage_list_title'             => 'People with this document',
    'usage_list_none'              => 'Nobody has it yet: it can be deleted without breaking anything.',

    // Messages
    'created'                      => 'Document type created.',
    'saved'                        => 'Document type updated.',
    'deactivated'                  => 'Document type deactivated: it is no longer offered on a person record.',
    'delete_about'                 => 'You are about to delete ":name". It will go to the recycle bin.',
    'deleted_description_required' => 'State why it is being deleted.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // What blocks a delete
    'in_use_cannot_delete'         => 'Cannot delete: :count person(s) have this document. If it is no longer used, deactivate it — records that already have it keep it.',
    'deactivate_instead'           => 'Deactivate instead',
    'bulk_skipped_protected'       => ':count not deleted (in use)',
    'bulk_all_protected'           => 'None could be deleted: all :count selected are in use.',

    // Validation
    'code_required'                => 'The "Abbreviation" field is required.',
    'code_unique'                  => 'Another document type in that country already uses that abbreviation.',
    'is_active_required'           => 'The status field is required.',
    'max_length_gte'               => 'The maximum length cannot be smaller than the minimum.',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Document types',
        'step1_body'  => 'The ID document catalogue. It shows on every person record; add the missing one here instead of asking for it to be programmed.',
        'step2_title' => 'Search',
        'step2_body'  => 'Type the abbreviation or the full name and the list filters itself. Nothing to press.',
        'step3_title' => 'Columns',
        'step3_body'  => 'Show or hide columns; the choice is remembered for next time.',
        'step4_title' => 'In use',
        'step4_body'  => 'The "People with this document" column says how many records depend on each row. With one or more it can no longer be deleted: it gets deactivated instead.',
        'step5_title' => 'Bulk actions',
        'step5_body'  => 'Tick rows to activate, deactivate or delete in bulk. Rows in use are skipped and you are told how many.',
    ],
];
