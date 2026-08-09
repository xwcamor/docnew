<?php

return [
    'singular'                     => 'Puesto de trabajo',
    'plural'                       => 'Puestos de trabajo',
    'record'                       => 'puesto',
    'records'                      => 'puestos',
    'new'                          => 'Crear puesto de trabajo',
    'id'                           => 'N°',

    'index_title'                  => 'Puestos de trabajo',
    'index_subtitle'               => 'Los puestos de cada sede. Un plan de trabajo se hace en uno de ellos.',
    'create_title'                 => 'Crear puesto de trabajo',
    'create_subtitle'              => 'Primero se elige la sede: el puesto pertenece a ella y no se ofrece en las demás.',
    'edit_title'                   => 'Editar puesto de trabajo',
    'delete_title'                 => 'Eliminar puesto de trabajo',
    'show_title'                   => 'Puesto de trabajo — Información',
    'trash_title'                  => 'Papelera de puestos de trabajo',
    'empty_hint'                   => 'Crea el primer puesto. Antes tiene que existir la sede a la que pertenece.',

    // Campos
    'name'                         => 'Nombre',
    'name_help'                    => 'Como se conoce el puesto en obra. Se lee junto a su sede al elegirlo en un plan.',
    'work_location_id'             => 'Sede',
    'work_location_help'           => 'La sede a la que pertenece el puesto. Se elige primero: al registrar un plan solo se ofrecen los puestos de la sede escogida.',
    'is_active'                    => 'Estado',
    'is_active_help'               => 'Un puesto inactivo deja de ofrecerse al registrar un plan. Los planes que ya lo nombran siguen funcionando.',
    'filter_name'                  => 'Buscar',
    'usage_count'                  => 'Planes que lo usan',
    'usage_list_title'             => 'Planes de trabajo en este puesto',
    'usage_list_none'              => 'Ningún plan lo usa todavía: se puede eliminar sin romper nada.',
    'usage_list_more'              => 'Y :count más.',
    'usage_kind_work_plan'         => 'Plan de trabajo',
    'usage_state_in_progress'      => 'En curso',
    'usage_state_done'             => 'Terminado',

    // Mensajes
    'created'                      => 'Puesto de trabajo creado.',
    'saved'                        => 'Puesto de trabajo actualizado.',
    'deactivated'                  => 'Puesto desactivado: deja de ofrecerse al registrar planes nuevos.',
    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'         => 'No se puede eliminar: hay :count plan(es) de trabajo hechos en este puesto, y un plan firmado es evidencia. Desactívalo para que deje de ofrecerse.',
    'deactivate_instead'           => 'Desactivar en su lugar',
    'bulk_skipped_protected'       => ':count sin eliminar (en uso)',
    'bulk_all_protected'           => 'No se pudo eliminar ninguno: los :count seleccionados están en uso.',

    // Validación
    'name_required'                => 'El campo «Nombre» es obligatorio.',
    'name_unique'                  => 'Ya existe otro puesto con ese nombre en esa sede.',
    'is_active_required'           => 'El campo estado es obligatorio.',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Puestos de trabajo',
        'step1_body'  => 'El catálogo de puestos. Cada uno pertenece a una sede, y el selector del plan solo enseña los de la sede elegida.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'Escribe en la barra y la lista se filtra sola. No hace falta pulsar nada.',
        'step3_title' => 'Columnas',
        'step3_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step4_title' => 'En uso',
        'step4_body'  => 'La columna «Planes que lo usan» dice de cuántos registros depende cada fila. Con uno o más, ya no se puede borrar: se desactiva.',
        'step5_title' => 'Operaciones masivas',
        'step5_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Las que estén en uso se saltan y se te dice cuántas.',
    ],
];
