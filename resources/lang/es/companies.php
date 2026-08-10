<?php

return [
    'singular'      => 'Empresa',
    'plural'        => 'Empresas',
    'record'        => 'empresa',
    'records'       => 'empresas',
    'new'           => 'Crear empresa',
    'id'            => 'N°',

    'index_title'    => 'Empresas',
    'index_subtitle' => 'Empresas contratistas que ejecutan los trabajos en obra.',
    'create_title'   => 'Crear empresa',
    'create_subtitle'=> 'Da de alta una empresa contratista con su documento y su razón social.',
    'edit_title'     => 'Editar empresa',
    'delete_title'   => 'Eliminar empresa',
    'show_title'     => 'Empresa — Información',
    'trash_title'    => 'Papelera de empresas',
    'form_create_hint' => 'Da de alta una empresa contratista con su documento y su razón social.',
    'empty_hint'      => 'Crea la primera empresa o importa un lote desde Excel para empezar.',

    // ── Campos ──────────────────────────────────────────────────────────────
    'name'                     => 'Nombre comercial',
    'name_help'                => 'Con el que se la conoce en obra. Se propone solo a partir de la razón social quitándole la forma societaria (ej: ACME SERVICIOS GENERALES).',
    'name_placeholder'         => 'Ej: ACME',
    'complete_name'            => 'Razón social',
    'complete_name_help'       => 'El nombre legal, tal como figura en el documento tributario. Donde hay consulta oficial, se rellena solo.',
    'complete_name_placeholder'=> 'Ej: Acme Servicios Generales S.A.C.',
    'num_doc'                  => 'N° de documento',
    'num_doc_help'             => 'Identifica a la empresa. No se repite dentro del mismo país.',
    // Sin ejemplo fijo: un RUC de once cifras no le sirve a quien está dando
    // de alta una contratista chilena. La forma la dice el tipo elegido.
    'num_doc_placeholder'      => 'Número del documento',
    // El documento de la empresa cambia de país en país: RUC en Perú y
    // Ecuador, RUT en Chile, CUIT en Argentina, NIT en Colombia, RFC en
    // México, CNPJ en Brasil. Sale del mismo catálogo que el DNI de una
    // persona, con `scope` de empresa.
    // La razón social nace bloqueada: es el nombre legal, sale en la cabecera
    // de cada PDF firmado, y tecleada a mano es como la misma empresa acaba
    // tres veces en la base escrita de tres formas.
    'razon_esperando_doc'      => 'Se rellena al escribir el :type.',
    'razon_de_sunat'           => 'Razón social de SUNAT.',
    'razon_editar_a_mano'      => 'Editar a mano',
    'document'                 => 'Documento',
    'doc_type'                 => 'Tipo de documento',
    'doc_type_help'            => 'Qué documento lleva la empresa en su país. Sale del catálogo de tipos de documento.',
    'doc_type_invalid'         => 'Tipo de documento no válido para ese país. Admitidos: :types.',
    'doc_type_ninguno'         => 'Sin tipos para este país',
    'doc_type_sin_catalogo'    => 'Este país no tiene ningún documento de empresa en el catálogo. Créalo en Tipos de documento, con ámbito «Empresa». La empresa se puede guardar sin él.',
    'num_doc_too_short'        => 'Un :type tiene al menos :min caracteres.',
    'num_doc_too_long'         => 'Un :type tiene como mucho :max caracteres.',
    'num_doc_faltan'           => 'Faltan :n dígitos.',
    'num_doc_falta_uno'        => 'Falta 1 dígito.',
    'country'                  => 'País',
    'country_help'             => 'País donde está registrada. Decide qué documento lleva —cada país tiene el suyo— y, junto con el número, define su unicidad.',
    'people_count'             => 'Personas',
    'plans_count'              => 'Planes',
    'is_active'                => 'Estado',
    'is_active_help'           => 'Si está inactiva, la empresa no aparecerá al crear un plan de trabajo.',
    'filter_name'              => 'Nombre, razón social o documento',
    'search_placeholder'       => 'Buscar por nombre, razón social o documento…',

    'edit_hint'   => 'Modificar este registro',
    'delete_hint' => 'Eliminar (queda en papelera)',
    'restore_hint'=> 'Volverá a estar disponible en el listado principal.',

    'created' => 'Empresa creada.',
    'saved'   => 'Empresa actualizada.',
    'deleted' => 'Empresa eliminada.',

    // Lo que impide borrar: una empresa con planes o gente detrás no se va del
    // listado, se desactiva (docs/UI.md §6).
    'in_use_cannot_delete_plans'  => 'No se puede eliminar: tiene :count plan(es) de trabajo a su nombre. Desactívala para que no salga en los planes nuevos.',
    'in_use_cannot_delete_people' => 'No se puede eliminar: tiene :count persona(s) vinculada(s). Desactívala para que no salga en los planes nuevos.',
    'bulk_skipped_in_use'         => ':count empresa(s) no se eliminaron: tienen planes o gente a su nombre.',
    'delete_dependents_title'     => 'Lo que cuelga de esta empresa',
    'delete_dependents_plans'     => ':count plan(es) de trabajo',
    'delete_dependents_people'    => ':count persona(s) vinculada(s)',

    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Export
    'export_filename'           => 'exportacion_empresas',
    'import_template_filename'  => 'plantilla-empresas.xlsx',
    'export_title'              => 'Reporte de empresas',
    'export_limit_exceeded'     => 'El export en :format excede el límite (:count filas vs :limit máximo). Usa CSV para datasets grandes (sin límite).',
    'export_format_limit_hint'  => 'Máximo :limit filas para este formato. Usa CSV para datasets grandes.',
    'export_no_limit_hint'      => 'Sin límite — recomendado para datasets grandes.',

    // Validación
    'name_required'            => 'El nombre de la empresa es obligatorio.',
    'name_unique'              => 'Ya existe una empresa con este nombre comercial.',
    'complete_name_required'   => 'La razón social es obligatoria.',
    'num_doc_required'         => 'El número de documento es obligatorio.',
    'num_doc_unique'           => 'Ya existe una empresa con este documento en el mismo país.',
    'country_required'         => 'Indica el país de la empresa.',
    'name_duplicate_in_batch'  => 'Nombre duplicado dentro del mismo batch.',
    'is_active_required'       => 'El campo estado es obligatorio.',
    'import_super_blocked'     => 'Un super sin workspace asignado no puede importar (el match por nombre podría actualizar registros de otro workspace).',
    // Errores del import: la columna del documento es obligatoria en la tabla,
    // así que una celda vacía no puede llegar a la base. El fichero trae empresas
    // de varios países en la misma hoja, así que aquí no hay una sigla que decir.
    'import_err_num_doc_required' => 'Falta el número de documento. Es obligatorio para dar de alta una empresa.',
    'import_err_num_doc_too_long' => 'El número de documento supera los 20 caracteres.',
    'import_err_no_country'       => 'Tu usuario no tiene país asignado y el país es obligatorio. Pídele a un administrador que te lo asigne antes de importar.',

    // Edit All
    'edit_all_title'    => 'Empresas — Editar todo',
    'edit_all_subtitle' => 'Edita nombre y estado de muchas empresas a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'  => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all' => 'Guardar todo',
    'edit_all_discard'  => 'Descartar cambios',
    'edit_all_no_results' => 'No hay empresas que coincidan con el filtro.',

    'table_headers' => [
        'editable_name'   => 'Nombre (editable)',
        'editable_status' => 'Estado (editable)',
    ],

    'ruc_buscando'      => 'Consultando en SUNAT…',
    'ruc_encontrado'    => 'Datos de SUNAT: razón social rellenada.',
    'ruc_no_encontrado' => 'SUNAT no devolvió datos para este RUC. Escribe la razón social a mano.',
    'ruc_error'         => 'No se pudo consultar SUNAT. Escribe los datos a mano.',
];
