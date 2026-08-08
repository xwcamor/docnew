<?php

return [
    'singular'                     => 'Área',
    'plural'                       => 'Áreas',
    'record'                       => 'área',
    'records'                      => 'áreas',
    'new'                          => 'Crear área',
    'id'                           => 'N°',

    'index_title'                  => 'Áreas',
    'index_subtitle'               => 'La parte de la sede donde se trabaja: es lo que se anota en el plan.',
    'create_title'                 => 'Crear área',
    'create_subtitle'              => 'Un área nueva se puede elegir en un plan en cuanto se guarda.',
    'edit_title'                   => 'Editar área',
    'delete_title'                 => 'Eliminar área',
    'show_title'                   => 'Área — Información',
    'trash_title'                  => 'Papelera de áreas',
    'empty_hint'                   => 'Crea la primera área.',

    // Campos
    'name'                         => 'Nombre',
    'name_help'                    => 'Como se conoce el área en obra (Planta, Almacén, Patio de maniobras).',
    'country'                      => 'País',
    'country_help'                 => 'El país al que pertenece. Los catálogos se llevan por país porque la normativa de seguridad no es la misma en todos.',
    'is_active'                    => 'Estado',
    'is_active_help'               => 'Un área inactiva deja de ofrecerse al registrar un plan. Los planes que ya la nombran siguen funcionando.',
    'filter_name'                  => 'Buscar',
    'usage_count'                  => 'Planes que la usan',
    'usage_list_title'             => 'Planes de trabajo en esta área',
    'usage_list_none'              => 'Ningún plan la usa todavía: se puede eliminar sin romper nada.',

    // Mensajes
    'created'                      => 'Área creada.',
    'saved'                        => 'Área actualizada.',
    'deactivated'                  => 'Área desactivada: deja de ofrecerse al registrar planes nuevos.',
    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'         => 'No se puede eliminar: hay :count plan(es) de trabajo hechos en esta área, y un plan firmado es evidencia. Desactívala para que deje de ofrecerse.',
    'deactivate_instead'           => 'Desactivar en su lugar',
    'bulk_skipped_protected'       => ':count sin eliminar (en uso)',
    'bulk_all_protected'           => 'No se pudo eliminar ninguno: los :count seleccionados están en uso.',

    // Validación
    'name_required'                => 'El campo «Nombre» es obligatorio.',
    'name_unique'                  => 'Ya existe otra área con ese nombre en el mismo país.',
    'is_active_required'           => 'El campo estado es obligatorio.',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Áreas',
        'step1_body'  => 'El catálogo de áreas. Es la parte de la sede donde se trabaja, y va anotada en cada plan.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'Escribe en la barra y la lista se filtra sola. No hace falta pulsar nada.',
        'step3_title' => 'Columnas',
        'step3_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step4_title' => 'En uso',
        'step4_body'  => 'La columna «Planes que la usan» dice de cuántos registros depende cada fila. Con uno o más, ya no se puede borrar: se desactiva.',
        'step5_title' => 'Operaciones masivas',
        'step5_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Las que estén en uso se saltan y se te dice cuántas.',
    ],
];
