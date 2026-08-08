<?php

return [
    'singular' => 'Rol aprobador',
    'plural'   => 'Roles aprobadores',
    'record'   => 'rol aprobador',
    'records'  => 'roles aprobadores',
    'new'      => 'Crear rol aprobador',
    'id'       => 'N°',

    'index_title'      => 'Roles aprobadores',
    'index_subtitle'   => 'Quién puede firmar la aprobación de un plan de trabajo.',
    'create_title'     => 'Crear rol aprobador',
    'create_subtitle'  => 'Un rol nuevo se puede usar en las reglas del flujo en cuanto se guarda.',
    'edit_title'       => 'Editar rol aprobador',
    'delete_title'     => 'Eliminar rol aprobador',
    'show_title'       => 'Rol aprobador — Información',
    'trash_title'      => 'Papelera de roles aprobadores',
    'form_create_hint' => 'Un rol nuevo se puede usar en las reglas del flujo en cuanto se guarda.',
    'empty_hint'       => 'Crea el primer rol o importa el catálogo desde Excel.',

    // Campos
    'code'            => 'Código',
    'code_help'       => 'La clave con la que las reglas nombran al rol: minúsculas, sin espacios ni acentos (ej: jefe_de_obra). Se corrige solo mientras escribes.',
    'code_placeholder' => 'jefe_de_obra',
    'name_es'         => 'Nombre en español',
    'name_es_help'    => 'Como se lee en la aplicación cuando está en español.',
    'name_en'         => 'Nombre en inglés',
    'name_en_help'    => 'Como se lee en la aplicación cuando está en inglés.',
    'sort_order'      => 'Orden',
    'sort_order_help' => 'En qué posición aparece al elegir un rol. No es el nivel del flujo: ese va en cada regla.',
    'is_active'       => 'Estado',
    'is_active_help'  => 'Un rol inactivo deja de ofrecerse al crear reglas. Las reglas que ya lo usaban siguen funcionando.',
    'filter_name'     => 'Buscar',
    'rules_count'     => 'Reglas que lo usan',
    'system'          => 'Del sistema',
    'system_hint'     => 'Lo trae el sistema. Se puede renombrar, pero ni su código cambia ni se borra: las reglas sembradas y la migración de datos lo nombran así.',

    // Mensajes
    'created'     => 'Rol aprobador creado.',
    'saved'       => 'Rol aprobador actualizado.',
    'deleted'     => 'Rol aprobador eliminado.',
    'deactivated' => 'Rol aprobador desactivado: deja de ofrecerse al crear reglas nuevas.',

    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'    => 'No se puede eliminar: hay :count regla(s) del flujo que lo usan. Si ya no quieres que se ofrezca, desactívalo — las reglas que lo nombran seguirán funcionando.',
    'system_cannot_delete'    => 'No se puede eliminar «:code»: es uno de los roles que trae el sistema y las reglas sembradas lo nombran por su código.',
    'deactivate_instead'      => 'Desactivar en su lugar',
    'bulk_skipped_protected'  => ':count sin eliminar (en uso o del sistema)',
    'bulk_all_protected'      => 'Ninguno se pudo eliminar: los :count seleccionados están en uso o son del sistema.',
    'rules_using_it'          => 'Reglas del flujo que firman con este rol',
    'rules_none'              => 'Ninguna regla lo usa todavía: se puede eliminar sin romper nada.',
    'all_work_types'          => 'Todos los tipos',

    // Exportar / importar
    'export_filename'          => 'roles_aprobadores',
    'import_template_filename' => 'plantilla-roles-aprobadores.xlsx',
    'export_title'             => 'Catálogo de roles aprobadores',
    'export_limit_exceeded'    => 'La exportación en :format supera el límite (:count filas frente a :limit). Usa CSV para volúmenes grandes.',
    'export_format_limit_hint' => 'Máximo :limit filas en este formato. Usa CSV si necesitas más.',
    'export_no_limit_hint'     => 'Sin límite — recomendado para volúmenes grandes.',

    // Validación
    'code_required'        => 'El código es obligatorio.',
    'code_unique'          => 'Ya existe un rol con este código.',
    'code_regex'           => 'El código solo admite minúsculas, números y guion bajo (ej: jefe_de_obra).',
    'name_es_required'     => 'El nombre en español es obligatorio.',
    'name_en_required'     => 'El nombre en inglés es obligatorio.',
    'is_active_required'   => 'El campo estado es obligatorio.',
    'import_super_blocked' => 'Un super sin workspace asignado no puede importar: escribiría sobre el catálogo global que usan todos los workspaces.',
    'import_code_required' => 'Falta el código, que es lo que identifica al rol.',
    'import_names_required'=> 'Un rol nuevo necesita nombre en español y en inglés.',

    // Editar en masa
    'edit_all_title'      => 'Roles aprobadores — Editar todo',
    'edit_all_subtitle'   => 'Cambia el nombre en español y el estado de varios roles de una vez. El código no se toca aquí: es una clave.',
    'edit_all_changes'    => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all'   => 'Guardar todo',
    'edit_all_discard'    => 'Descartar cambios',
    'edit_all_duplicate_names' => 'Hay nombres repetidos: dos roles con el mismo nombre en español se confunden al elegir quién firma.',
    'edit_all_no_results' => 'Ningún rol coincide con el filtro.',

    'table_headers' => [
        'editable_name'   => 'Nombre en español (editable)',
        'editable_status' => 'Estado (editable)',
    ],

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Roles aprobadores',
        'step1_body'  => 'El catálogo de quién puede firmar un plan. Antes eran tres nombres fijos en el código; ahora añadir un «Cliente» o un «Jefe de obra» es una fila.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'La búsqueda mira a la vez el código y los dos nombres, porque nadie tiene por qué recordar en qué columna estaba.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación de filtros, columnas y orden, y vuelve a ella con un clic.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step5_title' => 'Exportar e importar',
        'step5_body'  => 'La exportación se prepara en segundo plano y avisa al terminar. La importación enseña una vista previa antes de confirmar.',
        'step6_title' => 'Editar muchos a la vez',
        'step6_body'  => 'Cambia nombre y estado de varios roles y confírmalo todo en un solo guardado.',
        'step7_title' => 'En uso',
        'step7_body'  => 'La columna «Reglas que lo usan» dice cuántas reglas del flujo firman con ese rol. Con una o más, el rol ya no se puede borrar: se desactiva.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Los roles en uso y los del sistema se saltan y se te dice cuántos.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Vuelve a abrir este tour cuando quieras desde el menú de herramientas.',
    ],
];
