<?php

/**
 * Documentos.
 *
 * En obra estos objetos son «documentos»: el AST, el Pare Tome 5, la inspección
 * de EPP. Así se llamaban en el sistema anterior (`plan_documents`,
 * `f1_documents`) y así los nombra quien los usa. `form_templates` es el nombre
 * de la tabla, no el de la cosa.
 *
 * Todo este archivo decía «Marca» —el módulo se generó clonando `Brand`— y en
 * inglés ni siquiera se tradujo: las pantallas ponían «FormTemplate».
 */
return [
    'singular'      => 'Documento',
    'plural'        => 'Documentos',
    'record'        => 'documento',
    'records'       => 'documentos',
    'new'           => 'Crear documento',
    'id'            => 'N°',

    'index_title'    => 'Documentos',
    'index_subtitle' => 'Los documentos que se llenan en obra: AST, Pare Tome 5, inspección de EPP, herramientas.',
    'create_title'   => 'Crear documento',
    'create_subtitle'=> 'Completa los datos para crear un documento nuevo.',
    'edit_title'     => 'Editar documento',
    'delete_title'   => 'Eliminar documento',
    'show_title'     => 'Documento — Información',
    'trash_title'    => 'Papelera de documentos',
    'form_create_hint' => 'Completa los datos para crear un documento nuevo.',
    'empty_hint'      => 'Crea el primer documento o importa un lote desde Excel para empezar.',
    'name_placeholder' => 'Ej: AST (Análisis de Seguridad en el Trabajo)',

    'country' => 'País',
    'country_help' => 'De qué país es este documento. Los catálogos de riesgo, EPP y herramientas que ofrece son los de ese país, y sólo los planes de ahí pueden usarlo.',

    // Publicación
    'publish' => 'Publicar',
    'unpublish' => 'Despublicar',
    'publish_hint' => 'Publicar lo pone a disposición de los planes. Mientras esté en borrador, ningún plan puede usarlo.',
    'unpublish_hint' => 'Deja de ofrecerse en planes nuevos. Los que ya lo tienen conservan lo que se llenó.',
    'publish_confirm' => '¿Publicar este documento? A partir de ahora los planes podrán usarlo.',
    'unpublish_confirm' => '¿Despublicar? Deja de ofrecerse en los planes nuevos; lo ya llenado no se toca.',
    'published' => 'Documento publicado: ya se puede usar en los planes.',
    'unpublished' => 'Documento despublicado.',
    'publish_empty' => 'Un documento con campos propios no se puede publicar vacío: primero hay que definirle los campos.',
    'publish_blocked_no_fields' => 'No se puede publicar todavía: es un documento con campos propios y aún no tiene ninguno. La pantalla para definirlos está pendiente; mientras tanto, «Sólo foto del papel» sí se puede publicar.',

    // Estado de publicación — color y palabra, nunca sólo el color.
    'status'           => 'Publicación',
    'status_help'      => 'En borrador no lo ve ningún plan. Publicado se puede añadir a un plan de trabajo.',
    'status_draft'     => 'Borrador',
    'status_published' => 'Publicado',
    'status_archived'  => 'Archivado',
    'published_at'     => 'Publicado el',

    // Cómo se llena
    'kind'            => 'Cómo se llena',
    'kind_help'       => 'Con campos: se responde en la tablet. Sólo foto del papel: se sube la hoja firmada escaneada. Ambos: se responde y además se adjunta el papel.',
    'kind_structured' => 'Con campos en la tablet',
    'kind_upload_only'=> 'Sólo foto del papel',
    'kind_hybrid'     => 'Campos y foto del papel',
    'kind_locked_published' => 'Ya está publicado: no se cambia cómo se llena. Despublícalo primero, o saca una versión nueva.',

    'name'      => 'Nombre',
    'name_help' => 'El nombre completo, como se dice en obra (ej: «AST (Análisis de Seguridad en el Trabajo)»).',
    'code'      => 'Código',
    'code_help' => 'La sigla corta con la que se le conoce (ej: AST, PTF, EPP, IHM).',
    'version'      => 'Versión',
    'version_help' => 'Sube sola cuando se saca una versión nueva de un documento ya publicado. Lo ya firmado conserva la versión con la que se llenó.',
    'is_active' => 'Estado',
    'is_active_help' => 'Si está inactivo, el documento no aparecerá en los selectores de otros módulos.',
    'requires_signature' => 'Necesita firma',
    'filter_name' => 'Nombre',
    'filter_status' => 'Publicación',

    'edit_hint'   => 'Modificar este registro',
    'delete_hint' => 'Eliminar (queda en papelera)',
    'restore_hint'=> 'Volverá a estar disponible en el listado principal.',

    'created' => 'Documento creado. Está en borrador: publícalo para que los planes puedan usarlo.',
    'saved'   => 'Documento actualizado.',
    'deleted' => 'Documento eliminado.',

    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Export
    'export_filename'           => 'exportacion_documentos',
    'import_template_filename'  => 'plantilla-documentos.xlsx',
    'export_title'              => 'Reporte de Documentos',
    'export_limit_exceeded'     => 'El export en :format excede el límite (:count filas vs :limit máximo). Usa CSV para datasets grandes (sin límite).',
    'export_format_limit_hint'  => 'Máximo :limit filas para este formato. Usa CSV para datasets grandes.',
    'export_no_limit_hint'      => 'Sin límite — recomendado para datasets grandes.',

    // Validation
    'name_required'            => 'El campo nombre es obligatorio.',
    'name_unique'              => 'Ya existe un documento con este nombre.',
    'code_unique'              => 'Ya existe un documento con este código.',
    'name_duplicate_in_batch'  => 'Nombre duplicado dentro del mismo batch.',
    'is_active_required'       => 'El campo estado es obligatorio.',
    'import_super_blocked'     => 'Un super sin workspace asignado no puede importar (el match por nombre podría actualizar registros de otro workspace).',
    'import_country_missing'   => 'Tu usuario no tiene país asignado y un documento pertenece a un país: pídeselo a un administrador antes de importar.',

    // Edit All
    'edit_all_title'    => 'Documentos — Editar Todo',
    'edit_all_subtitle' => 'Edita nombre y estado de muchos documentos a la vez. Click "Guardar todo" para confirmar, "Cancelar" para descartar.',
    'edit_all_changes'  => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all' => 'Guardar todo',
    'edit_all_discard'  => 'Descartar cambios',
    'edit_all_no_results' => 'No hay documentos que coincidan con el filtro.',

    'table_headers' => [
        'editable_name'   => 'Nombre (editable)',
        'editable_status' => 'Estado (editable)',
    ],

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Documentos',
        'step1_body'  => 'Aquí se definen los documentos que se llenan en obra. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca y filtra por nombre, código, publicación, estado y fechas. Los filtros activos aparecen como chips arriba de la tabla.',
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
