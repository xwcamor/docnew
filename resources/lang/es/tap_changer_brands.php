<?php

return [
    'singular'      => 'Marca de conmutador',
    'plural'        => 'Marcas de conmutador',
    'record'        => 'marca de conmutador',
    'records'       => 'marcas de conmutador',
    'new'           => 'Crear marca de conmutador',
    'id'            => 'N°',

    'index_title'    => 'Marcas de conmutador',
    'index_subtitle' => 'Catalogo de marcas de conmutador (fabricantes del cambiador de tomas).',
    'create_title'   => 'Crear marca de conmutador',
    'create_subtitle'=> 'Completa los datos para crear un nuevo marca de conmutador.',
    'edit_title'     => 'Editar marca de conmutador',
    'delete_title'   => 'Eliminar marca de conmutador',
    'show_title'     => 'Marca de conmutador — Información',
    'trash_title'    => 'Papelera de marcas de conmutador',
    'form_create_hint' => 'Completa los datos para crear un nuevo marca de conmutador.',
    'empty_hint'      => 'Crea el primer marca de conmutador o importa un lote desde Excel para empezar.',
    'name_placeholder' => 'Ej: Reinhausen, ABB, Huaming',

    'name'      => 'Nombre',
    'name_help' => 'Marca/fabricante del conmutador (ej: Reinhausen, ABB).',
    'code'      => 'Código',
    'code_help' => 'Codigo o identificador interno (opcional).',
    'sort_order' => 'Orden',
    'is_active' => 'Estado',
    'is_active_help' => 'Si está inactivo, el marca de conmutador no aparecerá en los selectores de otros módulos.',
    'filter_name' => 'Nombre',

    'edit_hint'   => 'Modificar este registro',
    'delete_hint' => 'Eliminar (queda en papelera)',
    'restore_hint'=> 'Volverá a estar disponible en el listado principal.',

    'created' => 'Marca de conmutador creado.',
    'saved'   => 'Marca de conmutador actualizado.',
    'deleted' => 'Marca de conmutador eliminado.',

    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Export
    'export_filename'           => 'exportacion_marcas_conmutador',
    'import_template_filename'  => 'plantilla-marcas-conmutador.xlsx',
    'export_title'              => 'Reporte de Marcas de Conmutador',
    'export_limit_exceeded'     => 'El export en :format excede el límite (:count filas vs :limit máximo). Usa CSV para datasets grandes (sin límite).',
    'export_format_limit_hint'  => 'Máximo :limit filas para este formato. Usa CSV para datasets grandes.',
    'export_no_limit_hint'      => 'Sin límite — recomendado para datasets grandes.',

    // Validation
    'name_required'            => 'El campo nombre es obligatorio.',
    'name_unique'              => 'Este marca de conmutador ya existe.',
    'code_unique'              => 'Ya existe un marca de conmutador con este código.',
    'name_duplicate_in_batch'  => 'Nombre duplicado dentro del mismo batch.',
    'is_active_required'       => 'El campo estado es obligatorio.',
    'import_super_blocked'     => 'Un super sin workspace asignado no puede importar (el match por nombre podría actualizar registros de otro workspace).',

    // Edit All
    'edit_all_title'    => 'Marcas de conmutador — Editar Todo',
    'edit_all_subtitle' => 'Edita nombre y estado de muchos marcas de conmutador a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'  => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all' => 'Guardar todo',
    'edit_all_discard'  => 'Descartar cambios',
    'edit_all_no_results' => 'No hay marcas de conmutador que coincidan con el filtro.',

    'table_headers' => [
        'editable_name'   => 'Nombre (editable)',
        'editable_status' => 'Estado (editable)',
    ],

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Marcas de conmutador',
        'step1_body'  => 'Este es el catálogo de marcas de conmutador. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, código, estado y fechas. Los filtros activos aparecen como chips arriba de la tabla.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic. Cada usuario tiene las suyas propias.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas y se recuerda tu elección. Las marcadas como "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — se te notificará cuando esté listo. Importa desde Excel/CSV con vista previa antes de confirmar.',
        'step6_title' => 'Editar muchos a la vez',
        'step6_body'  => '"Editar todo" permite modificar nombre y estado de varios registros juntos. Después se confirman todos los cambios en un solo guardado.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca un registro como favorito. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, desactivar, eliminar o restaurar. Funciona con cientos de filas; los lotes grandes se procesan en segundo plano.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar — los últimos registros que viste en cualquier módulo.',
    ],
];
