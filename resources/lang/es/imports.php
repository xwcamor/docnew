<?php

return [
    // Modal & sections
    'title'                => 'Importar registros',
    'file'                 => 'Archivo',
    'download_template'    => 'Descargar plantilla',
    'mode'                 => 'Modo de importación',
    'preview_result'       => 'Resultado de la previsualización',
    'errors'               => 'Errores',
    'preview_changes'      => 'Vista previa de cambios',

    // Dropzone
    'drag_file_strong'     => 'Arrastra tu archivo',
    'drag_or_click'        => 'o haz clic para seleccionar',
    'formats_hint'         => 'Formatos: .xlsx, .xls, .csv · Máximo 10 MB',

    // Modes
    'mode_update_or_create'      => 'Actualizar o crear',
    'mode_update_or_create_hint' => 'Si ya existe un registro con el mismo nombre, lo actualiza. Si no existe, lo crea.',
    'mode_create_only'           => 'Solo crear',
    'mode_create_only_hint'      => 'Crea solo los registros nuevos. Omite los nombres ya existentes.',

    // Stats
    'stat_create'          => 'Crear',
    'stat_update'          => 'Actualizar',
    'stat_skip'            => 'Omitir',
    'stat_errors'          => 'Errores',

    // Action tags
    'action_create'        => 'Crear',
    'action_update'        => 'Actualizar',
    'action_skip'          => 'Omitir',

    // Alerts
    'no_changes'           => 'No hay cambios que aplicar',
    'no_changes_desc'      => 'Todos los registros del archivo ya existen idénticos en la base.',
    'rows_with_problems'   => ':count fila tiene problemas',
    'rows_with_problems_plural' => ':count filas tienen problemas',
    'rows_with_problems_desc'   => 'Estas filas se omitirán al confirmar. Las demás se importarán normalmente.',

    // Errors
    'process_failed'             => 'No se pudo procesar el archivo. Verifica el formato.',
    'err_unique_violation'       => 'El archivo contiene valores que ya existen en otro registro (duplicado de un campo único). Revisa los datos y reinténtalo.',
    'err_not_null_violation'     => 'Falta un campo obligatorio en alguna fila del archivo. Verifica que todas las columnas requeridas estén completas.',
    'err_foreign_key_violation'  => 'Una fila hace referencia a un registro relacionado que no existe (ej. región o locale inválido).',

    // Table columns
    'col_row'              => 'Fila',
    'col_name'             => 'Nombre',
    'col_active'           => 'Activo',
    'col_action'           => 'Acción',
    'col_value'            => 'Valor',
    'col_error'            => 'Error',

    // Buttons
    'preview_import'       => 'Previsualizar import',
    'confirm_import'       => 'Confirmar import (:count)',

    // Row-level error messages
    'err_name_required'    => 'El nombre es obligatorio.',
    'err_cod_required'      => 'El código (RUC/CUIT/RFC/NIT) es obligatorio.',
    'err_code_too_long'     => 'El código supera los 40 caracteres.',
    'err_code_duplicate'    => 'El código ":value" ya existe en otro registro.',
    'err_name_too_long'    => 'El nombre supera los 255 caracteres.',
    'err_duplicate_in_file' => 'Duplicado: ya aparece en la fila :row de este archivo.',
    'err_fk_not_found'     => 'No se encontró ":value" en el catálogo de :field. Verifica el código/nombre o créalo antes de importar.',
    'err_not_numeric'      => ':field debe ser numérico (recibido: ":value").',
    'err_bad_year'         => 'Año de fabricación inválido: ":value" (usa un año entre 1900 y el actual).',
    'err_bad_paper'        => 'Tipo de papel inválido: ":value" (usa kraft o upgraded).',
    'err_bad_phases'       => 'Número de fases inválido: ":value" (usa 1, 2 o 3).',
    'err_bad_date'         => 'Fecha inválida: ":value" (usa el formato AAAA-MM-DD).',
    'err_country_required'  => 'El país (country_iso) es obligatorio para crear un cliente.',
    'err_country_unknown'   => 'El código ISO de país ":value" no corresponde a ningún país activo.',
    'err_cod_country_dup'   => 'El código ":value" ya existe para otro cliente en ese país.',

    // Template
    'template_filename'         => 'plantilla-regiones.xlsx',
    'template_author'           => 'Sistema',
    'template_is_active_help'   => 'Valores aceptados: 1, 0, true, false, sí, no, activo, inactivo. Vacío = activo por defecto.',
    'template_sample_1'         => 'América del Sur',
    'template_sample_2'         => 'Europa Oriental',
];
