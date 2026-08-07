<?php

return [
    // Titles
    'singular'        => 'Idioma',
    'plural'          => 'Idiomas',
    'new'             => 'Nuevo idioma',
    'records'         => 'idiomas',
    'record'          => 'idioma',
    'empty_hint'      => 'Crea el primer idioma o importa un lote desde Excel para empezar.',
    'name_placeholder'=> 'Ej: Español',
    'iso_code_placeholder' => 'Ej: es, es_AR, en_US',
    'form_create_hint'=> 'Completa los datos para crear un nuevo idioma.',
    'delete_hint'     => 'Se borrará la información del idioma.',
    'delete_about'    => 'Está a punto de eliminar el idioma :name.',
    'restore_hint'    => 'Volverá a estar disponible en el listado principal.',

    // Columns
    'index_title'     => 'Listado de Idiomas',
    'create_title'    => 'Idioma - Crear',
    'show_title'      => 'Idioma - Información',
    'edit_title'      => 'Idioma - Editar',
    'delete_title'    => 'Idioma - Eliminar',
    'edit_all_title'  => 'Idioma - Editar Todo',
    'id'              => 'N°',
    'name'            => 'Nombre',
    'name_help'       => 'Nombre del idioma en su forma habitual (ej. Español, Inglés, Portugués).',
    'iso_code'        => 'Código ISO',
    'iso_code_help'   => 'ISO 639-1 (es) o BCP-47 short (es_AR)',
    'is_active'       => 'Estado',
    'is_active_help'  => 'Si está inactivo, el idioma no se podrá asignar a nuevos locales ni usuarios.',

    // Table headers for live edit
    'table_headers' => [
        'editable_name'     => 'Nombre (editable)',
        'editable_iso_code' => 'ISO (editable)',
        'editable_status'   => 'Estado (editable)',
    ],

    // Export
    'export_filename' => 'exportación_idiomas',
    'import_template_filename' => 'plantilla-idiomas.xlsx',
    'export_title'    => 'Reporte de Idiomas',
    'export_limit_exceeded'    => 'El export en :format excede el límite (:count filas vs :limit máximo). Use CSV para datasets grandes (sin límite).',
    'export_format_limit_hint' => 'Máximo :limit filas para este formato. Use CSV para datasets grandes.',
    'export_no_limit_hint'     => 'Sin límite — recomendado para datasets grandes.',

    // Validation messages
    'name_required'           => 'El campo nombre es obligatorio.',
    'name_unique'             => 'Este idioma ya existe.',
    'name_duplicate_in_batch' => 'Nombre duplicado dentro del mismo batch.',
    'iso_code_required'       => 'El código ISO es obligatorio.',
    'iso_code_regex'          => 'Formato inválido. Debe ser ISO 639-1 (es) o BCP-47 short (es_AR).',
    'iso_code_unique'         => 'Este código ISO ya está en uso por otro idioma.',
    'is_active_required'      => 'El campo estado es obligatorio.',

    // Deletion
    'deleted_description_required' => 'El motivo de eliminación es obligatorio.',
    'deleted_description_min'      => 'El motivo de eliminación debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo de eliminación no puede superar los 1000 caracteres.',

    // Edit All
    'edit_all_subtitle'   => 'Edita nombre, código ISO y estado de muchos idiomas a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'    => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all'   => 'Guardar todo',
    'edit_all_discard'    => 'Descartar cambios',
    'edit_all_no_results' => 'No hay idiomas que coincidan con el filtro.',

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Idiomas',
        'step1_body'  => 'Aquí gestiona los idiomas del sistema. Le mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, código ISO, estado, fechas e ID. Los filtros activos aparecen como chips arriba de la tabla.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic. Cada usuario tiene las suyas.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas y se recuerda tu elección. Las marcadas como "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word/CSV en segundo plano — se te notifica cuando está listo. Importa desde Excel/CSV con vista previa antes de confirmar.',
        'step6_title' => 'Editar muchos a la vez',
        'step6_body'  => '"Editar todo" permite modificar nombre, ISO y estado de varios registros juntos. Se confirman todos los cambios en un solo guardado.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca un registro como favorito. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, desactivar, eliminar o restaurar. Los lotes grandes se procesan en segundo plano.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar — los últimos registros vistos.',
        'step10_title' => 'Papelera',
        'step10_body'  => 'Abre la papelera y ve los registros eliminados (solo super). Desde ahí puedes restaurarlos o borrarlos definitivamente.',
        'step11_title' => 'Auditoría',
        'step11_body'  => 'Historial completo de cambios de este módulo: quién, qué y cuándo. Útil para investigar errores o auditorías.',
        'step12_title' => 'Crear nuevo',
        'step12_body'  => 'Botón principal para crear un nuevo registro. Atajo de teclado: Ctrl+N.',
    ],
];
