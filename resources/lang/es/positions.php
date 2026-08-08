<?php

return [
    'singular'                     => 'Cargo',
    'plural'                       => 'Cargos',
    'record'                       => 'cargo',
    'records'                      => 'cargos',
    'new'                          => 'Crear cargo',
    'id'                           => 'N°',

    'index_title'                  => 'Cargos',
    'index_subtitle'               => 'Qué hace cada persona en obra, y cuáles de esos cargos pueden firmar una aprobación.',
    'create_title'                 => 'Crear cargo',
    'create_subtitle'              => 'Un cargo nuevo se puede asignar a una persona en cuanto se guarda.',
    'edit_title'                   => 'Editar cargo',
    'delete_title'                 => 'Eliminar cargo',
    'show_title'                   => 'Cargo — Información',
    'trash_title'                  => 'Papelera de cargos',
    'empty_hint'                   => 'Crea el primer cargo: Técnico, Supervisor, Mecánico, Eléctrico.',

    // Campos
    'code'                         => 'Cargo',
    'code_help'                    => 'El cargo tal y como se dice en obra: Técnico, Supervisor, Mecánico, Eléctrico.',
    'country'                      => 'País',
    'country_help'                 => 'El país al que pertenece. Los catálogos se llevan por país porque la normativa de seguridad no es la misma en todos.',
    'is_signature_approver'        => 'Puede firmar aprobaciones',
    'is_signature_approver_help'   => 'Marca este cargo si quien lo tiene puede firmar la aprobación de un plan de trabajo. Sin la marca, la persona sale en el plan como trabajador pero no se le pide firma de aprobador.',
    'signature_approver_yes'       => 'Firma aprobaciones',
    'signature_approver_no'        => 'No firma',
    'is_active'                    => 'Estado',
    'is_active_help'               => 'Un cargo inactivo deja de ofrecerse al vincular a una persona. Quien ya lo tiene lo conserva.',
    'filter_name'                  => 'Buscar',
    'usage_count'                  => 'Personas con este cargo',
    'usage_list_title'             => 'Personas con este cargo',
    'usage_list_none'              => 'Nadie lo tiene todavía: se puede eliminar sin romper nada.',

    // Mensajes
    'created'                      => 'Cargo creado.',
    'saved'                        => 'Cargo actualizado.',
    'deactivated'                  => 'Cargo desactivado: deja de ofrecerse al vincular una persona a una empresa.',
    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'         => 'No se puede eliminar: hay :count persona(s) con este cargo. Si ya no se usa, desactívalo — las personas que ya lo tienen lo conservan.',
    'deactivate_instead'           => 'Desactivar en su lugar',
    'bulk_skipped_protected'       => ':count sin eliminar (en uso)',
    'bulk_all_protected'           => 'No se pudo eliminar ninguno: los :count seleccionados están en uso.',

    // Validación
    'code_required'                => 'El campo «Cargo» es obligatorio.',
    'code_unique'                  => 'Ya existe otro cargo con ese nombre en el mismo país.',
    'is_active_required'           => 'El campo estado es obligatorio.',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Cargos',
        'step1_body'  => 'El catálogo de cargos. Además de nombrar lo que hace cada persona, marca cuáles pueden firmar como aprobadores.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'Escribe en la barra y la lista se filtra sola. No hace falta pulsar nada.',
        'step3_title' => 'Columnas',
        'step3_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step4_title' => 'En uso',
        'step4_body'  => 'La columna «Personas con este cargo» dice de cuántos registros depende cada fila. Con uno o más, ya no se puede borrar: se desactiva.',
        'step5_title' => 'Operaciones masivas',
        'step5_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Las que estén en uso se saltan y se te dice cuántas.',
    ],
];
