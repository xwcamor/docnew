<?php

return [
    'singular' => 'Work type',
    'plural'   => 'Work types',
    'record'   => 'work type',
    'records'  => 'work types',
    'new'      => 'Create work type',
    'id'       => 'No.',

    'index_title'      => 'Work types',
    'index_subtitle'   => 'What kind of job each task is, and which safety documents it demands.',
    'create_title'     => 'Create work type',
    'create_subtitle'  => 'A work type is a code and a list of documents: the paperwork that kind of job forces you to fill in.',
    'edit_title'       => 'Edit work type',
    'delete_title'     => 'Delete work type',
    'show_title'       => 'Work type — Details',
    'trash_title'      => 'Deleted work types',
    'form_create_hint' => 'Country and code first; the documents are ticked below.',
    'empty_hint'       => 'Create the first work type: "Standard", "Lifting", "Work at height".',

    // Fields
    'code'          => 'Code',
    'country'       => 'Country',
    'code_help'     => 'What it is called on site: "Standard", "Lifting". It is what the supervisor picks when opening a plan.',
    'country_help'  => 'Work types belong to a country: each country has its own documents and procedure. Only documents from the same country can be demanded.',
    'is_active'     => 'Status',
    'is_active_help' => 'An inactive type stops being offered when creating plans. Existing plans keep theirs.',
    'filter_name'   => 'Search by code',

    // The documents — the heart of the module
    'forms'            => 'Documents',
    'forms_title'      => 'Documents this type demands',
    'forms_subtitle'   => 'Tick the documents this kind of job forces you to fill in, and whether each one is mandatory or optional.',
    'forms_count'      => 'Documents demanded',
    'forms_demanded'   => 'Demanded',
    'forms_not_demanded' => 'Not demanded',
    'required'         => 'Mandatory',
    'optional'         => 'Optional',
    'required_help'    => 'Mandatory: it cannot be removed from a plan, not even from a single one. Optional: it is proposed on every plan and can be removed.',
    'forms_empty'      => 'This type does not demand any document yet.',
    'forms_empty_country' => 'This country has no active documents yet. Create them under Documents before demanding them here.',
    'forms_none_warning' => 'A plan of this type would be created without a single safety document.',
    'forms_save'       => 'Save documents',
    'forms_discard'    => 'Discard changes',
    'forms_pending'    => '{0} No changes|{1} 1 unsaved change|[2,*] :count unsaved changes',
    'forms_draft'      => 'Draft',
    'forms_draft_hint' => 'A draft document cannot be filled in yet, so it cannot be demanded as mandatory. Publish it first.',
    'forms_search'     => 'Search document',
    'forms_no_results' => 'No document matches the search.',

    // A document this type demands that is no longer available under Documents
    // (deactivated, or from another country because the type moved). It is still
    // listed: if it dropped off the list, the next save would silently stop
    // demanding it without anyone deciding so.
    'forms_unavailable'      => 'Not available',
    'forms_unavailable_hint' => 'This type demands it, but it is no longer available under Documents (inactive or from another country). Plans keep being asked for it until you remove it here.',

    // Why the matrix cannot be touched
    'forms_readonly_locked'    => 'This work type is locked: the documents can be read but not changed. Remove the lock above to edit them.',
    'forms_readonly_locked_by_system' => 'This work type was locked by a system administrator: documents can be viewed but not changed. Only the system can remove the lock.',
    'forms_readonly_no_permission' => 'You do not have permission to change the documents this work type demands.',

    // What has to be spelled out, because it is what confuses people
    'how_it_works_title'    => 'How it works',
    'how_it_works_pivot'    => 'The documents ticked here are the ones proposed on every plan of this type.',
    'how_it_works_required' => 'A mandatory document cannot be removed from a single plan. That is what stops a job at height from going out without its JSA because someone was in a hurry.',
    'how_it_works_optional' => 'An optional document is proposed all the same, but it can be removed from a plan when it does not apply that day.',
    'how_it_works_live'     => 'The change reaches open plans the moment you save. Closed plans are left alone: their paperwork is already signed.',

    // Affected plans
    'open_plans'          => 'Open plans',
    'open_plans_none'     => 'No open plan of this type. The change affects nothing out on site.',
    'open_plans_warning'  => '{1} There is 1 open plan of this type: changing the documents changes what is asked of it today. Closed plans are left alone.|[2,*] There are :count open plans of this type: changing the documents changes what is asked of them today. Closed plans are left alone.',
    'closed_plans_safe'   => 'Closed plans are left alone.',

    // Messages
    'created' => 'Work type created.',
    'saved'   => 'Work type updated.',
    'deleted' => 'Work type deleted.',
    'saved_forms_no_plans'      => 'Documents updated. There is no open plan of this type, so nothing changes out on site.',
    'saved_forms_affects_plans' => '{1} Documents updated. 1 open plan of this type is affected; closed plans are left alone.|[2,*] Documents updated. :count open plans of this type are affected; closed plans are left alone.',

    'delete_about'                 => 'You are about to delete this work type. It will go to the bin.',
    'delete_warning_open_plans'    => 'There are :count open plan(s) of this type. Deleting it leaves them without a definition of which documents they need, and no new plan of this kind can be created.',
    'delete_warning_forms'         => 'This type demands :count document(s). Deleting it removes no document and no answer already given: it just stops demanding them.',
    'deleted_description_required' => 'State the reason for deletion.',
    'deleted_description_min'      => 'The reason must be at least 3 characters long.',
    'deleted_description_max'      => 'The reason cannot exceed 1000 characters.',

    // Validation
    'duplicate_code'               => 'There is already a work type with that code in this country. Two types with the same name split the document setup in half.',
    'country_required'             => 'The country is required.',
    'code_required'                => 'The code is required.',
    'is_active_required'           => 'The status field is required.',
    'form_template_unknown'        => 'That document does not exist or is inactive.',
    'form_template_other_country'  => 'Document :code belongs to another country: a plan of this type could never fill it in.',
    'form_template_draft_required' => 'Document :code is a draft and cannot be filled in yet. Publish it before demanding it as mandatory.',

    // Filters
    'filter_has_forms'     => 'With documents',
    'filter_has_forms_yes' => 'Demands at least one',
    'filter_has_forms_no'  => 'No document at all',
    'filter_form_template' => 'Demands the document',

    'table_headers' => [
        'forms_summary' => 'Documents (mandatory / total)',
    ],

    // History label: changing the list of demanded documents is WHAT this module
    // does, and without this key the entry would show the raw column name.
    'documents' => 'Demanded documents',

    // Welcome tour
    'tour' => [
        'step1_title' => 'Work types',
        'step1_body'  => 'This is where you decide which safety documents each kind of job demands. A lift needs paperwork a standard maintenance job does not.',
        'step2_title' => 'Search and filter',
        'step2_body'  => 'Filter by country, by status, or by "no document at all" — which is the case to fix first.',
        'step3_title' => 'The documents',
        'step3_body'  => 'On the details page each document is ticked as demanded or not, and among the demanded ones, mandatory or optional. A mandatory one cannot be removed from a single plan.',
        'step4_title' => 'Affected plans',
        'step4_body'  => 'Before saving you are told how many open plans the change reaches. Closed plans are never touched.',
        'step5_title' => 'Need a refresher?',
        'step5_body'  => 'Reopen this tour whenever you like from the tools menu.',
    ],
    'in_use_cannot_force_delete' => 'Cannot permanently delete: it has :count work plan(s). Those plans would be left without a type.',
    'restore_code_taken'  => 'Cannot restore: the code «:code» is already used by another work type. Change it there and try again.',
];
