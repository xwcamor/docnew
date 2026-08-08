<?php

return [
    'singular'                     => 'Nacionalidad',
    'plural'                       => 'Nacionalidades',
    'record'                       => 'nacionalidad',
    'records'                      => 'nacionalidades',
    'new'                          => 'Crear nacionalidad',
    'id'                           => 'N°',

    'index_title'                  => 'Nacionalidades',
    'index_subtitle'               => 'La nacionalidad que se anota en la ficha de cada persona.',
    'create_title'                 => 'Crear nacionalidad',
    'create_subtitle'              => 'Una nacionalidad nueva se puede elegir en la ficha de una persona en cuanto se guarda.',
    'edit_title'                   => 'Editar nacionalidad',
    'delete_title'                 => 'Eliminar nacionalidad',
    'show_title'                   => 'Nacionalidad — Información',
    'trash_title'                  => 'Papelera de nacionalidades',
    'empty_hint'                   => 'La tabla está vacía. Crea la primera nacionalidad para poder anotarla en las fichas.',

    // Campos
    'code'                         => 'Nacionalidad',
    'code_help'                    => 'Como se lee en la ficha de la persona (Peruana, Venezolana). Es el texto que se elige, no un código interno.',
    'country'                      => 'País',
    'country_help'                 => 'El país al que pertenece. Los catálogos se llevan por país porque la normativa de seguridad no es la misma en todos.',
    'is_active'                    => 'Estado',
    'is_active_help'               => 'Una nacionalidad inactiva deja de ofrecerse en la ficha de una persona. Las fichas que ya la tienen la conservan.',
    'filter_name'                  => 'Buscar',
    'usage_count'                  => 'Personas con esta nacionalidad',
    'usage_list_title'             => 'Personas con esta nacionalidad',
    'usage_list_none'              => 'Nadie la tiene todavía: se puede eliminar sin romper nada.',

    // Mensajes
    'created'                      => 'Nacionalidad creada.',
    'saved'                        => 'Nacionalidad actualizada.',
    'deactivated'                  => 'Nacionalidad desactivada: deja de ofrecerse en la ficha de una persona.',
    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'         => 'No se puede eliminar: hay :count persona(s) con esta nacionalidad. Si ya no se usa, desactívala — las fichas que ya la tienen la conservan.',
    'deactivate_instead'           => 'Desactivar en su lugar',
    'bulk_skipped_protected'       => ':count sin eliminar (en uso)',
    'bulk_all_protected'           => 'No se pudo eliminar ninguno: los :count seleccionados están en uso.',

    // Validación
    'code_required'                => 'El campo «Nacionalidad» es obligatorio.',
    'code_unique'                  => 'Ya existe otra nacionalidad con ese nombre en el mismo país.',
    'is_active_required'           => 'El campo estado es obligatorio.',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Nacionalidades',
        'step1_body'  => 'El catálogo de nacionalidades. Sale en la ficha de cada persona; la tabla arranca vacía y se llena aquí.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'Escribe en la barra y la lista se filtra sola. No hace falta pulsar nada.',
        'step3_title' => 'Columnas',
        'step3_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step4_title' => 'En uso',
        'step4_body'  => 'La columna «Personas con esta nacionalidad» dice de cuántos registros depende cada fila. Con uno o más, ya no se puede borrar: se desactiva.',
        'step5_title' => 'Operaciones masivas',
        'step5_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Las que estén en uso se saltan y se te dice cuántas.',
    ],
];
