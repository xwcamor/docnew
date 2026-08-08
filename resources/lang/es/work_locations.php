<?php

return [
    'singular'                     => 'Sede',
    'plural'                       => 'Sedes',
    'record'                       => 'sede',
    'records'                      => 'sedes',
    'new'                          => 'Crear sede',
    'id'                           => 'N°',

    'index_title'                  => 'Sedes',
    'index_subtitle'               => 'Dónde se trabaja: cada plan de trabajo sale de una sede.',
    'create_title'                 => 'Crear sede',
    'create_subtitle'              => 'Una sede nueva se puede elegir en un plan en cuanto se guarda.',
    'edit_title'                   => 'Editar sede',
    'delete_title'                 => 'Eliminar sede',
    'show_title'                   => 'Sede — Información',
    'trash_title'                  => 'Papelera de sedes',
    'empty_hint'                   => 'Crea la primera sede: sin ella no se puede registrar un plan de trabajo.',

    // Campos
    'name'                         => 'Nombre',
    'name_help'                    => 'Como se conoce la sede en obra (Lurín, Talara). Es lo que se lee al elegirla en un plan.',
    'country'                      => 'País',
    'country_help'                 => 'El país al que pertenece. Los catálogos se llevan por país porque la normativa de seguridad no es la misma en todos.',
    'is_active'                    => 'Estado',
    'is_active_help'               => 'Una sede inactiva deja de ofrecerse al registrar un plan. Los planes que ya la nombran siguen funcionando.',
    'filter_name'                  => 'Buscar',
    'usage_count'                  => 'Puestos y planes que la usan',
    'usage_list_title'             => 'Puestos de esta sede',
    'usage_list_none'              => 'La sede todavía no tiene puestos.',

    // Mensajes
    'created'                      => 'Sede creada.',
    'saved'                        => 'Sede actualizada.',
    'deactivated'                  => 'Sede desactivada: deja de ofrecerse al registrar planes nuevos.',
    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'         => 'No se puede eliminar: hay :count puesto(s) o plan(es) de trabajo que cuelgan de esta sede. Si ya no se usa, desactívala — lo que ya estaba registrado sigue en pie.',
    'deactivate_instead'           => 'Desactivar en su lugar',
    'bulk_skipped_protected'       => ':count sin eliminar (en uso)',
    'bulk_all_protected'           => 'No se pudo eliminar ninguno: los :count seleccionados están en uso.',

    // Validación
    'name_required'                => 'El campo «Nombre» es obligatorio.',
    'name_unique'                  => 'Ya existe otra sede con ese nombre en el mismo país.',
    'is_active_required'           => 'El campo estado es obligatorio.',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Sedes',
        'step1_body'  => 'El catálogo de sedes. Un plan de trabajo siempre sale de una, y los puestos cuelgan de ella.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'Escribe en la barra y la lista se filtra sola. No hace falta pulsar nada.',
        'step3_title' => 'Columnas',
        'step3_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step4_title' => 'En uso',
        'step4_body'  => 'La columna «Puestos y planes que la usan» dice de cuántos registros depende cada fila. Con uno o más, ya no se puede borrar: se desactiva.',
        'step5_title' => 'Operaciones masivas',
        'step5_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Las que estén en uso se saltan y se te dice cuántas.',
    ],
];
