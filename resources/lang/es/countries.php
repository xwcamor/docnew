<?php

return [
    // Titles
    'singular'        => 'País',
    'plural'          => 'Países',
    'new'             => 'Nuevo país',
    'records'         => 'países',
    'record'          => 'país',
    'empty_hint'      => 'Crea el primer país o importa un lote desde Excel para empezar.',
    'name_placeholder'=> 'Ej: Perú',
    'form_create_hint'=> 'Completa los datos para crear un nuevo país.',
    'delete_hint'     => 'Se borrará la información del país.',
    'delete_about'    => 'Está a punto de eliminar el país :name.',
    'restore_hint'    => 'Volverá a estar disponible en el listado principal.',

    // Columns
    'index_title'     => 'Listado de Países',
    'create_title'    => 'País - Crear',
    'show_title'      => 'País - Información',
    'edit_title'      => 'País - Editar',
    'delete_title'    => 'País - Eliminar',
    'edit_all_title'  => 'País - Editar Todo',
    'id'              => 'N°',
    'name'            => 'Nombre',
    'iso_code'        => 'Código ISO',
    'currency'        => 'Moneda',
    'timezone'        => 'Zona horaria',
    'region'          => 'Región',
    'default_locale'  => 'Locale por defecto',
    'is_active'       => 'Estado',

    'iso_code_placeholder' => 'PE',
    'currency_placeholder' => 'PEN',
    'timezone_placeholder' => 'America/Lima',
    'region_placeholder'   => 'Seleccionar región',
    'default_locale_placeholder' => 'Seleccionar locale',

    // Form field helpers
    'name_help'           => 'Nombre del país tal como aparecerá en listados y reportes.',
    'iso_code_help'       => 'Código ISO 3166-1 alpha-2 de dos letras mayúsculas (PE, BR, US).',
    'currency_help'       => 'Moneda oficial en formato ISO 4217 alpha-3 (PEN, USD, EUR).',
    'region_help'         => 'Región geográfica a la que pertenece el país. Solo se listan regiones activas.',
    'default_locale_help' => 'Locale que se usa por defecto para usuarios de este país (idioma y formato regional).',
    'timezone_help'       => 'Zona horaria principal del país en formato IANA (ej. America/Lima).',
    'is_active_help'      => 'Si está inactivo, no se podrá asignar a nuevos usuarios ni workspaces.',

    // Table headers for live edit
    'table_headers' => [
        'editable_name'           => 'Nombre (editable)',
        'editable_iso_code'       => 'ISO (editable)',
        'editable_currency'       => 'Moneda (editable)',
        'editable_timezone'       => 'Timezone (editable)',
        'editable_region'         => 'Región (editable)',
        'editable_default_locale' => 'Locale (editable)',
        'editable_status'         => 'Estado (editable)',
    ],

    // Export
    'export_filename' => 'exportacion_paises',
    'import_template_filename' => 'plantilla-paises.xlsx',
    'export_title'    => 'Reporte de Países',
    'export_limit_exceeded'    => 'El export en :format excede el límite (:count filas vs :limit máximo). Use CSV para datasets grandes (sin límite).',
    'export_format_limit_hint' => 'Máximo :limit filas para este formato. Use CSV para datasets grandes.',
    'export_no_limit_hint'     => 'Sin límite — recomendado para datasets grandes.',

    // Validation messages
    'name_required'           => 'El campo nombre es obligatorio.',
    'name_unique'             => 'Este país ya existe.',
    'name_duplicate_in_batch' => 'Nombre duplicado dentro del mismo batch.',

    'iso_code_required'       => 'El código ISO es obligatorio.',
    'iso_code_regex'          => 'Formato inválido. Debe ser ISO 3166-1 alpha-2 (PE, BR, US).',
    'iso_code_unique'         => 'Este código ISO ya está en uso por otro país.',

    'currency_required'       => 'La moneda es obligatoria.',
    'currency_regex'          => 'Formato inválido. Debe ser ISO 4217 alpha-3 (PEN, USD).',

    'timezone_required'       => 'La zona horaria es obligatoria.',
    'timezone_invalid'        => 'Zona horaria inválida. Use formato IANA (America/Lima).',

    'region_required'         => 'La región es obligatoria.',
    'region_invalid'          => 'La región seleccionada no existe o está inactiva.',

    'default_locale_required' => 'El locale por defecto es obligatorio.',
    'default_locale_invalid'  => 'El locale seleccionado no existe o está inactivo.',

    'is_active_required'      => 'El campo estado es obligatorio.',

    'deleted_description_required' => 'El motivo de eliminación es obligatorio.',
    'deleted_description_min'      => 'El motivo de eliminación debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo de eliminación no puede superar los 1000 caracteres.',

    // Edit All
    'edit_all_subtitle'   => 'Edita varios campos de muchos países a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'    => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all'   => 'Guardar todo',
    'edit_all_discard'    => 'Descartar cambios',
    'edit_all_no_results' => 'No hay países que coincidan con el filtro.',

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Países',
        'step1_body'  => 'Catálogo maestro de países con código ISO, moneda, zona horaria y región. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, código ISO, moneda, región, estado e ID. Los filtros activos aparecen como chips arriba.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden. Cada usuario tiene las suyas.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas; tu elección se recuerda. Las "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — se te notificará cuando esté listo. Importa desde Excel/CSV con vista previa.',
        'step6_title' => 'Editar muchas a la vez',
        'step6_body'  => '"Editar todo" permite modificar varios países juntos. Después se confirman todos los cambios en un solo guardado.',
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
