<?php

return [
    'singular' => 'Tipo de trabajo',
    'plural'   => 'Tipos de trabajo',
    'record'   => 'tipo de trabajo',
    'records'  => 'tipos de trabajo',
    'new'      => 'Crear tipo de trabajo',
    'id'       => 'N°',

    'index_title'      => 'Tipos de trabajo',
    'index_subtitle'   => 'Qué clase de maniobra es cada trabajo y qué documentos de seguridad exige.',
    'create_title'     => 'Crear tipo de trabajo',
    'create_subtitle'  => 'Un tipo de trabajo es un código y una lista de documentos: los papeles que esa clase de maniobra obliga a llenar.',
    'edit_title'       => 'Editar tipo de trabajo',
    'delete_title'     => 'Eliminar tipo de trabajo',
    'show_title'       => 'Tipo de trabajo — Información',
    'trash_title'      => 'Papelera de tipos de trabajo',
    'form_create_hint' => 'Primero el país y el código; los documentos se marcan abajo.',
    'empty_hint'       => 'Crea el primer tipo de trabajo: «Estandar», «Izaje», «Trabajo en altura».',

    // Campos
    'code'          => 'Código',
    'code_help'     => 'Como se le llama en obra: «Estandar», «Izaje». Es lo que el supervisor elige al abrir un plan.',
    'country'       => 'País',
    'country_help'  => 'Los tipos son por país: cada país tiene sus documentos y su procedimiento. Solo se pueden exigir documentos del mismo país.',
    'is_active'     => 'Estado',
    'is_active_help' => 'Un tipo inactivo deja de ofrecerse al crear planes. Los planes ya creados conservan el suyo.',
    'filter_name'   => 'Buscar por código',

    // Los documentos — el corazón del módulo
    'forms'            => 'Documentos',
    'forms_title'      => 'Documentos que exige este tipo',
    'forms_subtitle'   => 'Marca los documentos que esta clase de maniobra obliga a llenar, y si cada uno es obligatorio u opcional.',
    'forms_count'      => 'Documentos exigidos',
    'forms_demanded'   => 'Exigido',
    'forms_not_demanded' => 'No se exige',
    'required'         => 'Obligatorio',
    'optional'         => 'Opcional',
    'required_help'    => 'Obligatorio: no se puede quitar del plan, ni siquiera de uno suelto. Opcional: se propone en cada plan y se puede quitar.',
    'forms_empty'      => 'Este tipo no exige ningún documento todavía.',
    'forms_empty_country' => 'Este país no tiene documentos activos todavía. Créalos en Documentos antes de exigirlos aquí.',
    'forms_none_warning' => 'Un plan de este tipo se crearía sin ningún documento de seguridad.',
    'forms_save'       => 'Guardar documentos',
    'forms_discard'    => 'Descartar cambios',
    'forms_pending'    => '{0} Sin cambios|{1} 1 cambio sin guardar|[2,*] :count cambios sin guardar',
    'forms_draft'      => 'Borrador',
    'forms_draft_hint' => 'Un documento en borrador no se puede llenar todavía, así que no puede exigirse como obligatorio. Publícalo primero.',
    'forms_search'     => 'Buscar documento',
    'forms_no_results' => 'Ningún documento coincide con la búsqueda.',

    // Un documento que este tipo exige y que en Documentos ya no está disponible
    // (lo desactivaron, o es de otro país porque el tipo cambió de país). Se
    // sigue enseñando: si desapareciera de la lista, el siguiente guardado lo
    // quitaría del tipo sin que nadie lo hubiera decidido.
    'forms_unavailable'      => 'No disponible',
    'forms_unavailable_hint' => 'Este tipo lo exige, pero en Documentos ya no está disponible (inactivo o de otro país). Se sigue pidiendo en los planes hasta que lo quites aquí.',

    // Por qué la matriz no se deja tocar
    'forms_readonly_locked'    => 'Este tipo de trabajo está bloqueado: los documentos se pueden mirar pero no cambiar. Quita el candado arriba para editarlos.',
    'forms_readonly_locked_by_system' => 'Este tipo de trabajo lo bloqueó un administrador del sistema: los documentos se pueden mirar pero no cambiar. Solo el sistema puede quitar el candado.',
    'forms_readonly_no_permission' => 'No tienes permiso para cambiar los documentos que exige este tipo de trabajo.',

    // Lo que hay que explicar, porque es lo que confunde
    'how_it_works_title'    => 'Cómo funciona',
    'how_it_works_pivot'    => 'Los documentos que marcas aquí son los que se proponen en todo plan de este tipo.',
    'how_it_works_required' => 'Un documento obligatorio no se puede quitar de un plan suelto. Es lo que evita que un trabajo en altura salga sin AST porque alguien iba con prisa.',
    'how_it_works_optional' => 'Un documento opcional se propone igual, pero en el plan se puede quitar si ese día no aplica.',
    'how_it_works_live'     => 'El cambio alcanza a los planes abiertos en el momento de guardar. Los planes cerrados no se tocan: su documentación ya está firmada.',

    // Planes afectados
    'open_plans'          => 'Planes abiertos',
    'open_plans_none'     => 'Ningún plan abierto de este tipo. El cambio no afecta a nada que esté en obra.',
    'open_plans_warning'  => '{1} Hay 1 plan abierto de este tipo: cambiar los documentos cambia hoy mismo lo que se le pide. Los cerrados no se tocan.|[2,*] Hay :count planes abiertos de este tipo: cambiar los documentos cambia hoy mismo lo que se les pide. Los cerrados no se tocan.',
    'closed_plans_safe'   => 'Los planes cerrados no se tocan.',

    // Mensajes
    'created' => 'Tipo de trabajo creado.',
    'saved'   => 'Tipo de trabajo actualizado.',
    'deleted' => 'Tipo de trabajo eliminado.',
    'saved_forms_no_plans'      => 'Documentos actualizados. No hay ningún plan abierto de este tipo, así que no cambia nada en obra.',
    'saved_forms_affects_plans' => '{1} Documentos actualizados. 1 plan abierto de este tipo queda afectado; los cerrados no se tocan.|[2,*] Documentos actualizados. :count planes abiertos de este tipo quedan afectados; los cerrados no se tocan.',

    'delete_about'                 => 'Va a eliminar este tipo de trabajo. Quedará en papelera.',
    'delete_warning_open_plans'    => 'Hay :count plan(es) abierto(s) de este tipo. Al eliminarlo dejan de saber qué documentos se les exigen, y no se podrán crear planes nuevos de esta clase.',
    'delete_warning_forms'         => 'Este tipo exige :count documento(s). Eliminarlo no borra ningún documento ni ninguna respuesta ya dada: solo deja de exigirlos.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Validación
    'duplicate_code'               => 'Ya hay un tipo de trabajo con ese código en este país. Dos tipos con el mismo nombre parten en dos la configuración de documentos.',
    'country_required'             => 'El país es obligatorio.',
    'code_required'                => 'El código es obligatorio.',
    'is_active_required'           => 'El campo estado es obligatorio.',
    'form_template_unknown'        => 'Ese documento no existe o está inactivo.',
    'form_template_other_country'  => 'El documento :code es de otro país: un plan de este tipo nunca podría llenarlo.',
    'form_template_draft_required' => 'El documento :code está en borrador y no se puede llenar todavía. Publícalo antes de exigirlo como obligatorio.',

    // Filtros
    'filter_has_forms'     => 'Con documentos',
    'filter_has_forms_yes' => 'Exige al menos uno',
    'filter_has_forms_no'  => 'Sin ningún documento',
    'filter_form_template' => 'Exige el documento',

    'table_headers' => [
        'forms_summary' => 'Documentos (obligatorios / total)',
    ],

    // Etiqueta del historial: cambiar la lista de documentos exigidos es LO que
    // hace este módulo, y sin esta clave el registro saldría con el nombre de la
    // columna en crudo.
    'documents' => 'Documentos exigidos',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Tipos de trabajo',
        'step1_body'  => 'Aquí se decide qué documentos de seguridad exige cada clase de maniobra. Un izaje pide papeles que un mantenimiento estándar no necesita.',
        'step2_title' => 'Buscar y filtrar',
        'step2_body'  => 'Filtra por país, por estado o por «sin ningún documento» — que es el caso que hay que arreglar primero.',
        'step3_title' => 'Los documentos',
        'step3_body'  => 'En la ficha se marca cada documento como exigido o no, y dentro de los exigidos, obligatorio u opcional. Un obligatorio no se puede quitar de un plan suelto.',
        'step4_title' => 'Planes afectados',
        'step4_body'  => 'Antes de guardar se dice a cuántos planes abiertos alcanza el cambio. Los cerrados no se tocan nunca.',
        'step5_title' => '¿Necesitas un repaso?',
        'step5_body'  => 'Vuelve a abrir este tour cuando quieras desde el menú de herramientas.',
    ],
];
