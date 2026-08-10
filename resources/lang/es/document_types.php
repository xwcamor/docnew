<?php

return [
    'singular'                     => 'Tipo de documento',
    'plural'                       => 'Tipos de documento',
    'record'                       => 'tipo de documento',
    'records'                      => 'tipos de documento',
    'new'                          => 'Crear tipo de documento',
    'id'                           => 'N°',

    'index_title'                  => 'Tipos de documento',
    'index_subtitle'               => 'Qué documento lleva cada persona: DNI, carné de extranjería, pasaporte, PTP.',
    'create_title'                 => 'Crear tipo de documento',
    'create_subtitle'              => 'Se podrá elegir en cuanto se guarde: en la ficha de una persona o en la de una empresa, según el ámbito.',
    'edit_title'                   => 'Editar tipo de documento',
    'delete_title'                 => 'Eliminar tipo de documento',
    'show_title'                   => 'Tipo de documento — Información',
    'trash_title'                  => 'Papelera de tipos de documento',
    'empty_hint'                   => 'La tabla está vacía. Crea el primer tipo de documento para poder anotarlo en las fichas.',

    // Campos
    'code'                         => 'Sigla',
    'code_help'                    => 'La sigla que se lee en la ficha de la persona: DNI, CE, PTP, PASAPORTE. Es el texto que queda escrito en la ficha.',
    'scope'                        => 'Ámbito',
    'scope_help'                   => 'A quién pertenece el documento. El de una persona (DNI, carné de extranjería, PTP, pasaporte) y el de una empresa (RUC, RUT, CUIT, NIT) salen de este mismo catálogo, pero no se mezclan: la ficha de una persona solo ofrece los de persona y la de una empresa solo los de empresa. Si eliges mal, el tipo se guarda pero no aparece donde hace falta.',
    'scope_person'                 => 'Persona',
    'scope_company'                => 'Empresa',
    'name'                         => 'Nombre completo',
    'name_help'                    => 'Cómo se llama el documento: «Documento Nacional de Identidad», «Carné de Extranjería». La sigla va arriba.',
    'min_length'                   => 'Mínimo de caracteres',
    'max_length'                   => 'Máximo de caracteres',
    'length'                       => 'Longitud',
    'length_help'                  => 'Cuántos caracteres tiene el número. Es ayuda al dar de alta a una persona, no la condición para buscarla: el buscador de trabajadores va por coincidencia exacta del número. Déjalo en blanco si el documento no tiene una longitud fija.',
    'allowed_chars'                => 'Qué admite el número',
    'allowed_chars_help'           => 'Qué caracteres puede llevar el número, además de cuántos. El largo puede cuadrar y el contenido no: «1111111A» son ocho caracteres y no es un DNI. Con esto puesto, la ficha de la persona no deja ni teclear lo que no encaja. Los espacios no se admiten en ningún caso.',
    'allowed_chars_digits'         => 'Solo números',
    'allowed_chars_alphanumeric'   => 'Números y letras',
    'allowed_chars_any'            => 'Sin restricción',

    'length_range'                 => 'de :min a :max caracteres',
    'length_min_only'              => 'desde :min caracteres',
    'length_max_only'              => 'hasta :max caracteres',
    'length_none'                  => 'Sin longitud fija',
    'country'                      => 'País',
    'country_help'                 => 'El país al que pertenece. Los catálogos se llevan por país porque el documento no es el mismo en todos: el DNI peruano tiene 8 dígitos y el chileno ni siquiera se llama así.',
    'is_active'                    => 'Estado',
    'is_active_help'               => 'Un tipo de documento inactivo deja de ofrecerse en la ficha de una persona. Las fichas que ya lo tienen lo conservan.',
    'filter_name'                  => 'Buscar',
    'usage_count'                  => 'Personas con este documento',
    'usage_list_title'             => 'Personas con este documento',
    'usage_list_none'              => 'Nadie lo tiene todavía: se puede eliminar sin romper nada.',

    // Mensajes
    'created'                      => 'Tipo de documento creado.',
    'saved'                        => 'Tipo de documento actualizado.',
    'deactivated'                  => 'Tipo de documento desactivado: deja de ofrecerse en la ficha de una persona.',
    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Lo que impide borrar
    'in_use_cannot_delete'         => 'No se puede eliminar: hay :count persona(s) con este documento. Si ya no se usa, desactívalo — las fichas que ya lo tienen lo conservan.',
    'deactivate_instead'           => 'Desactivar en su lugar',
    'bulk_skipped_protected'       => ':count sin eliminar (en uso)',
    'bulk_all_protected'           => 'No se pudo eliminar ninguno: los :count seleccionados están en uso.',

    // Validación
    'code_required'                => 'El campo «Sigla» es obligatorio.',
    'scope_required'               => 'Indica si el documento es de una persona o de una empresa.',
    'code_unique'                  => 'Ya existe otro tipo de documento con esa sigla en el mismo país.',
    'is_active_required'           => 'El campo estado es obligatorio.',
    'max_length_gte'               => 'El máximo de caracteres no puede ser menor que el mínimo.',

    // Tour de bienvenida
    'tour' => [
        'step1_title' => 'Tipos de documento',
        'step1_body'  => 'El catálogo de documentos de identidad. Sale en la ficha de cada persona; añade aquí el que falte en vez de pedir que lo programen.',
        'step2_title' => 'Buscar',
        'step2_body'  => 'Escribe la sigla o el nombre completo y la lista se filtra sola. No hace falta pulsar nada.',
        'step3_title' => 'Columnas',
        'step3_body'  => 'Muestra u oculta columnas; la elección se recuerda para la próxima vez.',
        'step4_title' => 'En uso',
        'step4_body'  => 'La columna «Personas con este documento» dice de cuántos registros depende cada fila. Con uno o más, ya no se puede borrar: se desactiva.',
        'step5_title' => 'Operaciones masivas',
        'step5_body'  => 'Marca filas con las casillas para activar, desactivar o eliminar en bloque. Las que estén en uso se saltan y se te dice cuántas.',
    ],
];
