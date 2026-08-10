<?php

return [
    'singular'      => 'Persona',
    'plural'        => 'Personas',
    'record'        => 'persona',
    'records'       => 'personas',
    'new'           => 'Crear persona',
    'id'            => 'N°',

    'index_title'    => 'Personas',
    'index_subtitle' => 'Quiénes trabajan en obra: su documento, las empresas en las que están y si tienen la cara enrolada para firmar.',
    'create_title'   => 'Crear persona',
    'create_subtitle'=> 'Registra a la persona con su documento. La cara y la firma se capturan después, en obra.',
    'edit_title'     => 'Editar persona',
    'delete_title'   => 'Eliminar persona',
    'show_title'     => 'Persona — Información',
    'trash_title'    => 'Papelera de personas',
    'form_create_hint' => 'Registra a la persona con su documento. La cara y la firma se capturan después, en obra.',
    'empty_hint'      => 'Crea la primera persona o importa un lote desde Excel para empezar.',

    // ── Campos ──────────────────────────────────────────────────────────────
    'name'                 => 'Nombres',
    'name_help'            => 'Nombres de pila, tal como figuran en el documento.',
    'name_placeholder'     => 'Ej: Juan Carlos',
    'lastname'             => 'Apellidos',
    'lastname_help'        => 'Apellidos completos, tal como figuran en el documento.',
    'lastname_placeholder' => 'Ej: Pérez Gómez',
    'full_name'            => 'Persona',
    'document'             => 'Documento',
    'doc_type'             => 'Tipo de documento',
    'num_doc'              => 'N° de documento',
    'num_doc_help'         => 'Identifica a la persona. No se repite dentro del mismo país.',
    'num_doc_placeholder'  => 'Ej: 12345678',
    'num_doc_faltan'       => 'Faltan :n dígitos.',
    'num_doc_falta_uno'    => 'Falta 1 dígito.',

    // Consulta a RENIEC. Solo Perú y solo DNI: un carné de extranjería, un PTP
    // o un pasaporte no están en RENIEC.
    'dni_buscando'      => 'Consultando en RENIEC…',
    'dni_encontrado'    => 'Datos de RENIEC: nombre y apellidos rellenados.',
    'dni_no_encontrado' => 'RENIEC no devolvió datos para este DNI. Escribe el nombre a mano.',
    'dni_error'         => 'No se pudo consultar RENIEC. Escribe el nombre a mano.',
    'dni_verificado'    => 'Nombre verificado con RENIEC.',
    'dni_editar_a_mano' => 'Editar a mano',

    // Foto de referencia y firma guardada. Solo con `people.view_media`.
    'media_title'  => 'Foto y firma',
    'media_file'   => 'archivo',
    'media_help'   => 'Material interno: no lo ven ni el trabajador ni los perfiles de campo. La foto sirve para reconocer a la persona; la firma solo se imprime en los PDF, nunca se muestra en pantalla.',
    'photo'        => 'Foto de referencia',
    'no_photo'     => 'Sin foto',
    'no_signature' => 'Sin firma',
    'upload'       => 'Subir',
    'replace'      => 'Reemplazar',
    'photo_saved'     => 'Foto guardada.',
    'signature_saved' => 'Firma guardada.',
    'photo_source_uploaded' => 'Subida a mano',
    'photo_source_captured' => 'Capturada al firmar',
    'photo_source_migrated' => 'Del sistema anterior',
    'signer_face'  => 'Ver la cara con la que firmó',

    'country'              => 'País',
    'country_help'         => 'País del documento. Junto con el número define la unicidad de la persona.',
    'origin'               => 'Procedencia',
    // Ya no se pregunta: lo dice el tipo de documento. En Perú un peruano
    // lleva DNI y quien viene de fuera lleva carné de extranjería, PTP o
    // pasaporte, y el catálogo marca cuál es cuál.
    'origin_local'         => 'Del país',
    'origin_foreign'       => 'Extranjero',
    'birthdate'            => 'Fecha de nacimiento',
    'birthdate_help'       => 'Opcional. Se usa para los reportes de personal.',
    'roles'                => 'Roles en obra',
    'roles_help'           => 'Qué firma en obra. Un supervisor sólo aparece en el selector de aprobadores del plan si lo tiene marcado aquí.',
    'roles_placeholder'    => 'Trabajador, Supervisor, Supervisor HSE…',
    // El campo se deshabilita en vez de esconderse, y aquí va el motivo: sin
    // esto, quien abría el alta con una contratista elegida no veía los roles
    // por ninguna parte y tenía que adivinar de qué dependían.
    'roles_solo_los_mios'  => 'Solo para la gente de tu empresa',
    'roles_elige_empresa'  => 'Elige antes la empresa: los roles en obra solo se piden para la gente de la tuya.',
    'roles_no_es_mi_empresa' => 'Esta persona trabaja para una contratista, y quien autoriza un plan es del que contrata. Los roles en obra se piden solo para la gente de tu empresa, la que está marcada en Ajustes → Mi empresa.',
    'companies'            => 'Empresas',
    'companies_count'      => 'N° de empresas',
    // Etiquetas del aviso de borrado: «3 firmas dependen de este registro».
    'signatures_count'     => 'firmas',
    'approvals_count'      => 'aprobaciones de planes',
    'plans_count'          => 'planes de trabajo',
    'biometric'            => 'Rostro',
    'signatures'           => 'Firmas',
    'is_active'            => 'Estado',
    'is_active_help'       => 'Si está inactiva, la persona no aparecerá al asignar trabajadores a un plan.',
    'filter_name'          => 'Nombre o apellido',
    'search_placeholder'   => 'Buscar por nombre o apellido…',
    'trash_search_placeholder' => 'Buscar por nombre, apellido o documento…',

    'section_identity' => 'Identidad',
    'section_optional' => 'Datos adicionales',
    'section_work'     => 'Trabajo',
    // El cargo y la empresa faltaban por completo en esta pantalla, y en el
    // sistema anterior los dos son obligatorios en la ficha del trabajador.
    'company'          => 'Empresa',
    'company_help'     => 'Empresa para la que trabaja. Determina en qué planes puede entrar.',
    'position'         => 'Cargo',
    'position_help'    => 'Qué hace en obra: técnico, supervisor, mecánico, eléctrico.',

    'role_worker'         => 'Trabajador',
    'role_supervisor'     => 'Supervisor',
    'role_hse_supervisor' => 'Supervisor HSE',

    'biometric_yes' => 'Enrolado',
    'biometric_no'  => 'Sin enrolar',

    'edit_hint'   => 'Modificar este registro',
    'delete_hint' => 'Eliminar (queda en papelera)',
    'restore_hint'=> 'Volverá a estar disponible en el listado principal.',

    'created' => 'Persona creada.',
    'saved'   => 'Persona actualizada.',
    'deleted' => 'Persona eliminada.',

    'delete_about'                 => 'Va a eliminar ":name". Quedará en papelera.',
    'deleted_description_required' => 'Indica el motivo del borrado.',
    'deleted_description_min'      => 'El motivo debe tener al menos 3 caracteres.',
    'deleted_description_max'      => 'El motivo no puede superar los 1000 caracteres.',

    // Export
    'export_filename'           => 'exportacion_personas',
    'import_template_filename'  => 'plantilla-personas.xlsx',
    'export_title'              => 'Reporte de personas',
    'export_limit_exceeded'     => 'El export en :format excede el límite (:count filas vs :limit máximo). Usa CSV para datasets grandes (sin límite).',
    'export_format_limit_hint'  => 'Máximo :limit filas para este formato. Usa CSV para datasets grandes.',
    'export_no_limit_hint'      => 'Sin límite — recomendado para datasets grandes.',

    // Validación
    'name_required'              => 'Los nombres son obligatorios.',
    'lastname_required'          => 'Los apellidos son obligatorios.',
    'name_and_lastname_required' => 'Para dar de alta a alguien hacen falta nombres y apellidos.',
    'name_unique'                => 'Esta persona ya existe.',
    'num_doc_required'           => 'El número de documento es obligatorio.',
    'num_doc_too_short' => 'Un :type tiene al menos :min caracteres.',
    'num_doc_too_long' => 'Un :type tiene como mucho :max caracteres.',
    'num_doc_solo_cifras'      => 'Un :type lleva solo números.',
    'num_doc_cifras_y_letras'  => 'Un :type lleva solo números y letras.',
    'num_doc_sin_espacios'     => 'El número de documento no lleva espacios.',
    'num_doc_unique'             => 'Ya existe una persona con este documento en el mismo país.',
    'roles_solo_de_mi_empresa'   => 'Los roles en obra solo se le ponen a la gente de tu empresa: quien autoriza un plan es del que contrata.',
    'doc_type_invalid'           => 'Tipo de documento no válido para ese país. Admitidos: :types.',
    'doc_type_ninguno'           => 'Sin tipos para este país',
    'doc_type_sin_catalogo'      => 'Este país no tiene ningún documento de persona en el catálogo. Créalo en Tipos de documento, con ámbito «Persona».',
    'doc_masked_notice'          => 'El documento está oculto y no se puede modificar: hace falta el permiso de datos privados (people.view_private_info).',
    'country_required'           => 'Indica el país del documento.',
    'name_duplicate_in_batch'    => 'Documento duplicado dentro del mismo batch.',
    'is_active_required'         => 'El campo estado es obligatorio.',
    'import_super_blocked'       => 'Un super sin workspace asignado no puede importar (el match por documento podría actualizar registros de otro workspace).',
    // El Excel exige lo mismo que el formulario: sin empresa la persona no
    // entra en ningún plan y sin cargo no se sabe qué hace en obra.
    'import_company_position_required' => 'Para dar de alta hacen falta la empresa y el cargo (columnas company y position).',
    'import_role_unknown'        => 'Rol en obra desconocido: ":value". Admitidos: :roles.',
    'import_padded_zero'         => 'Se le devolvió el cero de delante que Excel se comió: :value.',

    // Edit All
    'edit_all_title'    => 'Personas — Editar todo',
    'edit_all_subtitle' => 'Corrige nombres y apellidos de varias personas a la vez — en la migración del sistema anterior llegaron muchos cruzados. El documento no se edita aquí.',
    'edit_all_changes'  => '{0} Sin cambios|{1} 1 cambio pendiente|[2,*] :count cambios pendientes',
    'edit_all_save_all' => 'Guardar todo',
    'edit_all_discard'  => 'Descartar cambios',
    'edit_all_no_results' => 'No hay personas que coincidan con el filtro.',

    'table_headers' => [
        'editable_name'     => 'Nombres (editable)',
        'editable_lastname' => 'Apellidos (editable)',
        'editable_status'   => 'Estado (editable)',
    ],

    // Onboarding tour
    'tour' => [
        'step1_title' => 'Bienvenido a Personas',
        'step1_body'  => 'Aquí está la gente que trabaja en obra. Te mostramos los puntos clave en menos de 1 minuto.',
        'step2_title' => 'Filtros',
        'step2_body'  => 'Busca por nombre, apellido o documento, y filtra por empresa, rol, país y si tiene la cara enrolada.',
        'step3_title' => 'Vistas guardadas',
        'step3_body'  => 'Guarda tu combinación favorita de filtros + columnas + orden y aplícala después con un clic. Cada usuario tiene las suyas propias.',
        'step4_title' => 'Columnas',
        'step4_body'  => 'Muestra/oculta columnas y se recuerda tu elección. Las marcadas como "obligatorias" no se pueden ocultar.',
        'step5_title' => 'Exportar & Importar',
        'step5_body'  => 'Exporta a Excel/PDF/Word en segundo plano — se te notificará cuando esté listo. Importa desde Excel/CSV con vista previa antes de confirmar.',
        'step6_title' => 'Editar muchas a la vez',
        'step6_body'  => '"Editar todo" permite corregir nombres y apellidos de varias personas juntas y guardarlos de una sola vez.',
        'step7_title' => 'Favoritos ★',
        'step7_body'  => 'La estrella ★ marca a una persona como favorita. Los favoritos aparecen siempre arriba del listado y cada usuario tiene los suyos.',
        'step8_title' => 'Operaciones masivas',
        'step8_body'  => 'Selecciona filas con los checkboxes — aparece una barra para activar, desactivar o eliminar. Los lotes grandes se procesan en segundo plano.',
        'step9_title' => '¿Necesitas un repaso?',
        'step9_body'  => 'Reabre este tour cuando quieras con el botón ? aquí arriba. También tienes "Recientes" en el menú del avatar — los últimos registros que viste en cualquier módulo.',
    ],
    'bulk_skipped_in_use'  => ':count no se pudo(ieron) dar de baja: tiene(n) planes, firmas o aprobaciones. Desactívala(s) en su lugar.',
];
