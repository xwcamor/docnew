<?php

return [
    'singular' => 'Regla de aprobación',
    'plural'   => 'Reglas de aprobación',
    'record'   => 'regla de aprobación',
    'records'  => 'reglas de aprobación',
    'new'      => 'Crear regla',
    'id'       => 'N°',

    'index_title'      => 'Reglas de aprobación',
    'index_subtitle'   => 'Qué firmas exige un plan de trabajo antes de darse por aprobado.',
    'create_title'     => 'Crear regla de aprobación',
    'create_subtitle'  => 'Una firma más en el flujo es una regla más: no hace falta tocar el sistema.',
    'edit_title'       => 'Editar regla de aprobación',
    'delete_title'     => 'Eliminar regla de aprobación',
    'show_title'       => 'Regla de aprobación — Información',
    'trash_title'      => 'Papelera de reglas de aprobación',
    'form_create_hint' => 'Una firma más en el flujo es una regla más: no hace falta tocar el sistema.',
    'empty_hint'       => 'Crea la primera regla o importa el flujo desde Excel.',

    // Campos
    'country'            => 'País',
    'country_help'       => 'Las reglas son por país: cada país tiene su procedimiento.',
    'work_type'          => 'Tipo de trabajo',
    'work_type_help'     => 'Déjalo vacío para que la regla valga para todos los tipos. Si lo acotas, la regla solo cuenta para ese tipo — y ese tipo dejará de heredar las reglas generales del país.',
    'all_work_types'     => 'Todos los tipos',
    'approver_role'      => 'Rol aprobador',
    'approver_role_help' => 'Quién firma. Sale del catálogo de roles aprobadores; si falta alguno, se añade allí.',
    'priority_level'     => 'Nivel',
    'level_short'        => 'Nivel',
    'priority_level_help' => 'Ordena el flujo: 1 firma primero. Con el orden obligatorio encendido, además no se puede firmar el nivel 3 mientras falte el 2.',
    'is_required'        => 'Obligatoria',
    'is_required_help'   => 'Una firma obligatoria frena a las de niveles posteriores hasta que se da. Una opcional no.',
    'required'           => 'Obligatoria',
    'optional'           => 'Opcional',
    'is_active'          => 'Estado',
    'is_active_help'     => 'Una regla inactiva deja de exigirse en los planes nuevos. Los planes ya creados conservan la aprobación que se les propuso.',
    'filter_name'        => 'Buscar por rol',

    // Lo que hay que explicar, porque es lo que confunde
    'how_it_works_title'    => 'Cómo se resuelve el flujo',
    'how_it_works_general'  => 'Una regla sin tipo de trabajo vale para todos los tipos de ese país.',
    'how_it_works_override' => 'Si un tipo de trabajo tiene reglas propias, mandan esas: las generales dejan de aplicarse a ese tipo. No se mezclan.',
    'how_it_works_level'    => 'El nivel ordena las firmas. Con el ajuste «aprobaciones en orden» encendido, además obliga a respetarlo.',
    'sequential_on'         => 'Orden obligatorio activado: no se puede firmar un nivel mientras quede pendiente una firma obligatoria de un nivel anterior.',
    'sequential_off'        => 'Orden obligatorio desactivado: el nivel ordena las firmas, pero se pueden dar en cualquier orden.',

    // Vista previa del flujo
    'preview_title'       => 'Vista previa del flujo',
    'preview_subtitle'    => 'Qué firmas quedan, tipo a tipo, con las reglas que hay ahora mismo.',
    'preview_open'        => 'Ver el flujo resultante',
    'preview_country'     => 'País',
    'preview_signatures'  => '{0} Ningún plan de este tipo exige firmas|{1} 1 firma|[2,*] :count firmas',
    'preview_source_own'       => 'Reglas propias del tipo',
    'preview_source_inherited' => 'Heredadas del país',
    'preview_source_country'   => 'Reglas generales del país',
    'preview_source_none'      => 'Sin reglas',
    'preview_none_warning'     => 'Un plan de este tipo se crearía sin ninguna aprobación pendiente.',
    'preview_empty_country'    => 'Este país no tiene tipos de trabajo activos todavía.',
    'flow_of_this_rule'        => 'El flujo del que forma parte esta regla',

    // Mensajes
    'created' => 'Regla creada.',
    'saved'   => 'Regla actualizada.',
    'deleted' => 'Regla eliminada.',

    'delete_about'                 => 'Va a eliminar esta regla. Quedará en papelera.',
    'delete_warning_approvals'     => 'Hay :count aprobación(es) de planes que nacieron de esta regla. Eliminarla no las borra ni desfirma nada: solo deja de exigirse en los planes nuevos.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Exportar / importar
    'export_filename'          => 'reglas_de_aprobacion',
    'import_template_filename' => 'plantilla-reglas-aprobacion.xlsx',
    'export_title'             => 'Reglas de aprobación',
    'export_limit_exceeded'    => 'La exportación en :format supera el límite (:count filas frente a :limit). Usa CSV para volúmenes grandes.',
    'export_format_limit_hint' => 'Máximo :limit filas en este formato. Usa CSV si necesitas más.',
    'export_no_limit_hint'     => 'Sin límite — recomendado para volúmenes grandes.',

    // Validación
    'approver_role_unknown'   => 'Ese rol no existe en el catálogo o está inactivo. Créalo primero en Roles aprobadores.',
    'duplicate_role_in_flow'  => 'Ese rol ya firma en este flujo (mismo país y mismo tipo de trabajo). Un rol no aprueba dos veces el mismo plan.',
    'country_required'        => 'El país es obligatorio.',
    'priority_level_required' => 'El nivel es obligatorio.',
    'is_active_required'      => 'El campo estado es obligatorio.',
    'import_super_blocked'    => 'Un super sin workspace asignado no puede importar reglas: no hay forma de saber a qué workspace pertenecen.',
    'import_country_unknown'  => 'No existe ningún país con ese código ISO.',
    'import_work_type_unknown'=> 'No existe ningún tipo de trabajo con ese código. Déjalo vacío si la regla vale para todos.',
    'import_level_invalid'    => 'El nivel tiene que ser un número entre 1 y 20.',

    // Editar en masa
    'edit_all_title'      => 'Reglas de aprobación — Editar todo',
    'edit_all_subtitle'   => 'Reordena los niveles y cambia qué firmas son obligatorias. El país, el tipo y el rol se editan en la ficha: cambiarlos es cambiar de regla.',
    'edit_all_changes'    => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all'   => 'Guardar todo',
    'edit_all_discard'    => 'Descartar cambios',
    'edit_all_no_results' => 'Ninguna regla coincide con el filtro.',
    'edit_all_duplicate_levels' => 'Hay dos firmas en el mismo nivel dentro del mismo flujo: el orden entre ellas queda al azar.',

    'table_headers' => [
        'editable_level'    => 'Nivel (editable)',
        'editable_required' => 'Obligatoria (editable)',
        'editable_status'   => 'Estado (editable)',
    ],

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Reglas de aprobación',
        'step1_body'  => 'Aquí se decide qué firmas exige un plan. Antes eran tres fijas; ahora una cuarta aprobación es una fila más.',
        'step2_title' => 'Buscar y filtrar',
        'step2_body'  => 'Filtra por país, tipo de trabajo o rol para ver solo el flujo que estás tocando.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación de filtros, columnas y orden, y vuelve a ella con un clic.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step5_title' => 'Exportar e importar',
        'step5_body'  => 'La plantilla de importación referencia país, tipo y rol por su código, así que el mismo archivo sirve en cualquier instalación.',
        'step6_title' => 'Editar muchas a la vez',
        'step6_body'  => 'Reordena niveles y cambia qué firmas son obligatorias sin entrar una por una.',
        'step7_title' => 'Vista previa del flujo',
        'step7_body'  => 'La pieza clave: enseña qué firmas quedan para cada tipo de trabajo, en orden. Sin ella no hay forma de saber qué acabas de configurar, porque un tipo con reglas propias deja de heredar las generales.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Vuelve a abrir este tour cuando quieras desde el menú de herramientas.',
    ],
];
