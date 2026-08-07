<?php

return [
    // Titles
    'singular'        => 'Locale',
    'plural'          => 'Locales',
    'new'             => 'Nuevo locale',
    'records'         => 'locales',
    'record'          => 'locale',
    'empty_hint'      => 'Crea el primer locale o importa un lote desde Excel para empezar.',
    'name_placeholder'=> 'Ej: Español (Perú)',
    'form_create_hint'=> 'Completa los datos para crear un nuevo locale.',
    'delete_hint'     => 'Se borrará la información del locale.',
    'delete_about'    => 'Está a punto de eliminar el locale :name.',
    'restore_hint'    => 'Volverá a estar disponible en el listado principal.',

    // Columns
    'index_title'     => 'Listado de Locales',
    'create_title'    => 'Locale - Crear',
    'show_title'      => 'Locale - Información',
    'edit_title'      => 'Locale - Editar',
    'delete_title'    => 'Locale - Eliminar',
    'edit_all_title'  => 'Locale - Editar Todo',
    'id'              => 'N°',
    'name'            => 'Nombre',
    'name_help'       => 'Nombre descriptivo del locale (ej. "Español (Perú)"). Visible para los usuarios al elegir su idioma.',
    'code'            => 'Código',
    'code_help'       => 'Código BCP-47: 2 letras minúsculas y opcionalmente _ y 2 mayúsculas (es, es_PE, en_US).',
    'language'        => 'Idioma',
    'language_help'   => 'Idioma maestro al que pertenece este dialecto regional. Solo se listan idiomas activos.',
    'is_active'       => 'Estado',
    'is_active_help'  => 'Si está inactivo, el locale no aparecerá en los selectores de país ni de usuario.',

    'code_placeholder'     => 'es_PE',
    'language_placeholder' => 'Seleccionar idioma',

    'table_headers' => [
        'editable_name'     => 'Nombre (editable)',
        'editable_code'     => 'Código (editable)',
        'editable_language' => 'Idioma (editable)',
        'editable_status'   => 'Estado (editable)',
    ],

    // Export
    'export_filename'          => 'exportacion_locales',
    'import_template_filename' => 'plantilla-locales.xlsx',
    'export_title'             => 'Reporte de Locales',
    'export_limit_exceeded'    => 'El export en :format excede el límite (:count filas vs :limit máximo). Use CSV para datasets grandes (sin límite).',
    'export_format_limit_hint' => 'Máximo :limit filas para este formato. Use CSV para datasets grandes.',
    'export_no_limit_hint'     => 'Sin límite — recomendado para datasets grandes.',

    // Validation messages
    'name_required'           => 'El campo nombre es obligatorio.',
    'name_unique'             => 'Este locale ya existe.',
    'name_duplicate_in_batch' => 'Nombre duplicado dentro del mismo batch.',

    'code_required' => 'El código es obligatorio.',
    'code_regex'    => 'Formato inválido. Debe ser BCP-47: 2 letras minúsculas, opcionalmente seguidas de _ y 2 letras mayúsculas (es, es_PE).',
    'code_unique'   => 'Este código ya está en uso por otro locale.',

    'language_required' => 'El idioma es obligatorio.',
    'language_invalid'  => 'El idioma seleccionado no existe o está inactivo.',

    'is_active_required' => 'El campo estado es obligatorio.',

    'deleted_description_required' => 'El motivo de eliminación es obligatorio.',
    'deleted_description_min'      => 'El motivo de eliminación debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo de eliminación no puede superar los 1000 caracteres.',

    // Edit All
    'edit_all_subtitle'   => 'Edita varios campos de muchos locales a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'    => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all'   => 'Guardar todo',
    'edit_all_discard'    => 'Descartar cambios',
    'edit_all_no_results' => 'No hay locales que coincidan con el filtro.',

    'tour' => [
        'step1_title' => 'Bienvenido a Locales',
        'step1_body'  => 'Catálogo de dialectos regionales (es_PE, en_US…) — cada uno apunta a un idioma maestro. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, código, idioma, estado e ID. Los filtros activos aparecen como chips arriba.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden. Cada usuario tiene las suyas.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas; tu elección se recuerda. Las "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — te notificamos cuando esté listo. Importa desde Excel/CSV con vista previa.',
        'step6_title' => 'Editar muchas a la vez',
        'step6_body'  => '"Editar todo" permite modificar varios locales juntos. Después se confirman todos los cambios en un solo guardado.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca un registro como favorito. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, desactivar, eliminar o restaurar.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar.',
        'step10_title' => 'Papelera',
        'step10_body'  => 'Abre la papelera y ve los registros eliminados (solo super). Desde ahí puedes restaurarlos o borrarlos definitivamente.',
        'step11_title' => 'Auditoría',
        'step11_body'  => 'Historial completo de cambios de este módulo: quién, qué y cuándo. Útil para investigar errores o auditorías.',
        'step12_title' => 'Crear nuevo',
        'step12_body'  => 'Botón principal para crear un nuevo registro. Atajo de teclado: Ctrl+N.',
    ],
];
