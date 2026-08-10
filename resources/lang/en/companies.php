<?php

return [
    'singular'      => 'Company',
    'plural'        => 'Companies',
    'record'        => 'company',
    'records'       => 'companies',
    'new'           => 'Create company',
    'id'            => 'No.',

    'index_title'    => 'Companies',
    'index_subtitle' => 'Contractors that carry out the work on site.',
    'create_title'   => 'Create company',
    'create_subtitle'=> 'Register a contractor with its tax ID and legal name.',
    'edit_title'     => 'Edit company',
    'delete_title'   => 'Delete company',
    'show_title'     => 'Company — Details',
    'trash_title'    => 'Companies trash',
    'form_create_hint' => 'Register a contractor with its tax ID and legal name.',
    'empty_hint'      => 'Create your first company or import a batch from Excel.',

    // ── Fields ──────────────────────────────────────────────────────────────
    'name'                     => 'Trade name',
    'name_help'                => 'What it is called on site. Proposed automatically from the legal name by dropping the company suffix (e.g. HITACHI ENERGY PERU).',
    'name_placeholder'         => 'E.g.: HITACHI',
    'complete_name'            => 'Legal name',
    'complete_name_help'       => 'The legal name, exactly as it appears on the tax document. It is what the tax ID lookup returns.',
    'complete_name_placeholder'=> 'E.g.: Hitachi Energy Perú S.A.',
    'num_doc'                  => 'Document number',
    'num_doc_help'             => 'Identifies the company. It cannot repeat within the same country.',
    'num_doc_placeholder'      => 'E.g.: 20512345678',
    // A company's document changes from country to country: RUC in Peru and
    // Ecuador, RUT in Chile, CUIT in Argentina, NIT in Colombia, RFC in
    // Mexico, CNPJ in Brazil. It comes from the same catalogue as a person's
    // ID document, with a company scope.
    // The legal name starts locked: it prints on the header of every signed
    // PDF, and typing it by hand is how the same company ends up in the
    // database three times, spelled three ways.
    'razon_esperando_ruc'      => 'Filled in once you type the tax ID.',
    'razon_de_sunat'           => 'Legal name from the tax authority.',
    'razon_editar_a_mano'      => 'Edit by hand',
    'document'                 => 'Document',
    'doc_type'                 => 'Document type',
    'doc_type_help'            => 'Which document the company carries in its country. In Peru it is the RUC.',
    'doc_type_invalid'         => 'Document type not valid for that country. Allowed: :types.',
    'num_doc_too_short'        => 'A :type has at least :min characters.',
    'num_doc_too_long'         => 'A :type has at most :max characters.',
    'num_doc_faltan'           => ':n digits to go.',
    'num_doc_falta_uno'        => '1 digit to go.',
    'country'                  => 'Country',
    'country_help'             => 'Where it is registered. Decides which document it carries — RUC in Peru, RUT in Chile, CUIT in Argentina — and, together with the number, defines uniqueness.',
    'people_count'             => 'People',
    'plans_count'              => 'Plans',
    'is_active'                => 'Status',
    'is_active_help'           => 'If inactive, the company will not show up when creating a work plan.',
    'filter_name'              => 'Name, legal name or tax ID',
    'search_placeholder'       => 'Search by name, legal name or tax ID…',

    'edit_hint'   => 'Edit this record',
    'delete_hint' => 'Delete (goes to trash)',
    'restore_hint'=> 'Will go back to the main list.',

    'created' => 'Company created.',
    'saved'   => 'Company updated.',
    'deleted' => 'Company deleted.',

    // What blocks a deletion: a company with plans or people behind it is
    // deactivated, not removed from the list (docs/UI.md §6).
    'in_use_cannot_delete_plans'  => 'Cannot delete: it has :count work plan(s) under its name. Deactivate it so it stops showing up on new plans.',
    'in_use_cannot_delete_people' => 'Cannot delete: it has :count linked person(s). Deactivate it so it stops showing up on new plans.',
    'bulk_skipped_in_use'         => ':count company(ies) were not deleted: they have plans or people under their name.',
    'delete_dependents_title'     => 'What depends on this company',
    'delete_dependents_plans'     => ':count work plan(s)',
    'delete_dependents_people'    => ':count linked person(s)',

    'delete_about'                 => 'You are about to delete ":name". It will go to the trash.',
    'deleted_description_required' => 'Provide a reason for the deletion.',
    'deleted_description_min'      => 'Reason must be at least 3 characters.',
    'deleted_description_max'      => 'Reason cannot exceed 1000 characters.',

    // Export
    'export_filename'           => 'companies_export',
    'import_template_filename'  => 'companies-template.xlsx',
    'export_title'              => 'Companies Report',
    'export_limit_exceeded'     => 'The :format export exceeds the limit (:count rows vs :limit max). Use CSV for large datasets (no limit).',
    'export_format_limit_hint'  => 'Max :limit rows for this format. Use CSV for large datasets.',
    'export_no_limit_hint'      => 'No limit — recommended for large datasets.',

    // Validation
    'name_required'            => 'The company name is required.',
    'name_unique'              => 'A company with this trade name already exists.',
    'complete_name_required'   => 'The legal name is required.',
    'num_doc_required'         => 'The tax ID is required.',
    'num_doc_unique'           => 'A company with this document already exists in the same country.',
    'country_required'         => 'Pick the company country.',
    'name_duplicate_in_batch'  => 'Duplicate name in the same batch.',
    'is_active_required'       => 'The status field is required.',
    'import_super_blocked'     => 'A super without an assigned workspace cannot import (name matching could update records from another workspace).',
    // Import errors: the tax ID column is NOT NULL in the table, so an empty
    // cell can never reach the database.
    'import_err_num_doc_required' => 'The tax ID is missing. It is required to create a company.',
    'import_err_num_doc_too_long' => 'The tax ID exceeds 20 characters.',
    'import_err_no_country'       => 'Your user has no country assigned and country is required. Ask an administrator to assign one before importing.',

    // Edit All
    'edit_all_title'    => 'Companies — Edit All',
    'edit_all_subtitle' => 'Edit name and status of multiple companies at once. Click "Save all" to confirm, "Cancel" to discard.',
    'edit_all_changes'  => '{0} No changes|{1} 1 pending change|[2,*] :count pending changes',
    'edit_all_save_all' => 'Save all',
    'edit_all_discard'  => 'Discard changes',
    'edit_all_no_results' => 'No companies match the filter.',

    'table_headers' => [
        'editable_name'   => 'Name (editable)',
        'editable_status' => 'Status (editable)',
    ],

    'ruc_buscando'      => 'Checking with SUNAT…',
    'ruc_encontrado'    => 'SUNAT data: legal name filled in.',
    'ruc_no_encontrado' => 'SUNAT returned no data for this tax ID. Type the legal name manually.',
    'ruc_error'         => 'Could not reach SUNAT. Type the data manually.',
];
