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
    'create_subtitle'=> 'Da de alta una empresa contratista con su RUC y su razón social.',
    'edit_title'     => 'Editar empresa',
    'delete_title'   => 'Eliminar empresa',
    'show_title'     => 'Empresa — Información',
    'trash_title'    => 'Papelera de empresas',
    'form_create_hint' => 'Da de alta una empresa contratista con su RUC y su razón social.',
    'empty_hint'      => 'Crea la primera empresa o importa un lote desde Excel para empezar.',

    // ── Campos ──────────────────────────────────────────────────────────────
    'name'                     => 'Nombre',
    'name_help'                => 'Nombre corto con el que se conoce a la empresa en obra (ej: HITACHI, LIMTEK).',
    'name_placeholder'         => 'Ej: HITACHI',
    'complete_name'            => 'Razón social',
    'complete_name_help'       => 'Nombre completo tal como figura en el documento de la empresa.',
    'complete_name_placeholder'=> 'Ej: Hitachi Energy Perú S.A.',
    'num_doc'                  => 'RUC',
    'num_doc_help'             => 'Documento de identificación tributaria. No se repite dentro del mismo país.',
    'num_doc_placeholder'      => 'Ej: 20512345678',
    'country'                  => 'País',
    'country_help'             => 'País donde está registrada la empresa. Junto con el RUC define su unicidad.',
    'people_count'             => 'Personas',
    'plans_count'              => 'Planes',
    'is_active'                => 'Estado',
    'is_active_help'           => 'Si está inactiva, la empresa no aparecerá al crear un plan de trabajo.',
    'filter_name'              => 'Nombre, razón social o RUC',
    'search_placeholder'       => 'Buscar por nombre, razón social o RUC…',

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
    'name_unique'              => 'Ya existe una empresa con este nombre.',
    'complete_name_required'   => 'La razón social es obligatoria.',
    'num_doc_required'         => 'El RUC es obligatorio.',
    'num_doc_unique'           => 'Ya existe una empresa con este RUC en el mismo país.',
    'country_required'         => 'Indica el país de la empresa.',
    'name_duplicate_in_batch'  => 'Nombre duplicado dentro del mismo batch.',
    'is_active_required'       => 'El campo estado es obligatorio.',
    'import_super_blocked'     => 'Un super sin workspace asignado no puede importar (el match por nombre podría actualizar registros de otro workspace).',
    // Errores del import: la columna del RUC es obligatoria en la tabla, así que
    // una celda vacía no puede llegar a la base.
    'import_err_num_doc_required' => 'Falta el RUC. Es obligatorio para dar de alta una empresa.',
    'import_err_num_doc_too_long' => 'El RUC supera los 20 caracteres.',
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

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Empresas',
        'step1_body'  => 'Aquí están las contratistas que ejecutan los trabajos. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, RUC, razón social, país y estado. Los filtros activos aparecen como chips arriba de la tabla.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic. Cada usuario tiene las suyas propias.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas y se recuerda tu elección. Las marcadas como "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — se te notificará cuando esté listo. Importa desde Excel/CSV con vista previa antes de confirmar.',
        'step6_title' => 'Editar muchas a la vez',
        'step6_body'  => '"Editar todo" permite modificar nombre y estado de varias empresas juntas. Después se confirman todos los cambios en un solo guardado.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca una empresa como favorita. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, desactivar o eliminar. Los lotes grandes se procesan en segundo plano.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar — los últimos registros que viste en cualquier módulo.',
    ],
    'ruc_buscando'      => 'Consultando en SUNAT…',
    'ruc_encontrado'    => 'Datos de SUNAT: razón social rellenada.',
    'ruc_no_encontrado' => 'SUNAT no devolvió datos para este RUC. Escribe la razón social a mano.',
    'ruc_error'         => 'No se pudo consultar SUNAT. Escribe los datos a mano.',
];
