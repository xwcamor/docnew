<?php

return [
    // Titles
    'singular'        => 'Región',
    'plural'          => 'Regiones',
    'new'             => 'Nueva región',
    'records'         => 'regiones',
    'record'          => 'región',
    'empty_hint'      => 'Crea la primera región o importa un lote desde Excel para empezar.',
    'name_placeholder'=> 'Ej: América del Sur',
    'form_create_hint'=> 'Completa los datos para crear una nueva región.',
    'delete_hint'     => 'Se borrará la información de la región.',
    'delete_about'    => 'Está a punto de eliminar la región :name.',
    'restore_hint'    => 'Volverá a estar disponible en el listado principal.',

    // Columns
    'index_title'     => 'Listado de Regiones',
    'create_title'    => 'Región - Crear',
    'show_title'      => 'Región - Información',
    'edit_title'      => 'Región - Editar',
    'delete_title'    => 'Región - Eliminar',
    'edit_all_title'  => 'Región - Editar Todo',
    'id'              => 'N°',
    'name'            => 'Nombre',
    'name_help'       => 'Nombre de la región geográfica tal como aparecerá en los selectores de país.',
    'is_active'       => 'Estado',
    'is_active_help'  => 'Si está inactiva, la región no se podrá asignar a nuevos países.',

    // Table headers for live edit
    'table_headers' => [
        'editable_name' => 'Nombre (editable)',
        'editable_status' => 'Estado (editable)',
    ],

    // Export
    'export_filename' => 'exportación_regiones',
    'import_template_filename' => 'plantilla-regiones.xlsx',
    'export_title'    => 'Reporte de Regiones',
    'export_limit_exceeded' => 'El export en :format excede el límite (:count filas vs :limit máximo). Use CSV para datasets grandes (sin límite).',
    'export_format_limit_hint' => 'Máximo :limit filas para este formato. Use CSV para datasets grandes.',
    'export_no_limit_hint'  => 'Sin límite — recomendado para datasets grandes.',

    // Validation messages
    // name
    'name_required'           => 'El campo nombre es obligatorio.',
    'name_unique'             => 'Esta región ya existe.',
    'name_duplicate_in_batch' => 'Nombre duplicado dentro del mismo batch.',

    // is_active
    'is_active_required' => 'El campo estado es obligatorio.',

    // deletion
    'deleted_description_required' => 'El motivo de eliminación es obligatorio.',
    'deleted_description_min'      => 'El motivo de eliminación debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo de eliminación no puede superar los 1000 caracteres.',

    // Edit All — edición inline masiva
    'edit_all_subtitle'  => 'Edita nombre y estado de muchas regiones a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'   => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all'  => 'Guardar todo',
    'edit_all_discard'   => 'Descartar cambios',
    'edit_all_no_results' => 'No hay regiones que coincidan con el filtro.',

    // Onboarding tour (4 pasos)
    'tour' => [
        'step1_title' => 'Bienvenido a Regiones',
        'step1_body'  => 'Este es el módulo master que vamos a clonar para los demás. Te mostramos los 4 puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, estado, fechas e ID. Los filtros activos aparecen como chips arriba de la tabla y se pueden quitar uno por uno.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic. Cada usuario tiene las suyas propias.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas y se recuerda tu elección. Las marcadas como "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — se te notificará cuando esté listo. Importa desde Excel/CSV con vista previa antes de confirmar.',
        'step6_title' => 'Editar muchas a la vez',
        'step6_body'  => '"Editar todo" permite modificar nombre y estado de varios registros juntos. Después se confirman todos los cambios en un solo guardado.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca un registro como favorito. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, desactivar, eliminar o restaurar. Funciona con cientos de filas; los lotes grandes se procesan en segundo plano.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar — los últimos registros que viste en cualquier módulo.',
        'step10_title' => 'Papelera',
        'step10_body'  => 'Abre la papelera y ve los registros eliminados (solo super). Desde ahí puedes restaurarlos o borrarlos definitivamente.',
        'step11_title' => 'Auditoría',
        'step11_body'  => 'Historial completo de cambios de este módulo: quién, qué y cuándo. Útil para investigar errores o auditorías.',
        'step12_title' => 'Crear nuevo',
        'step12_body'  => 'Botón principal para crear un nuevo registro. Atajo de teclado: Ctrl+N.',
    ],
];
