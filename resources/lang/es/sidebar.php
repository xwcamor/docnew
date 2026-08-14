<?php

/**
 * El menu lateral.
 *
 * Los grupos son los del trabajo, no los de la base de datos: primero lo del
 * dia a dia, despues lo que se configura una vez, y al final lo que solo toca
 * quien administra. Aqui habia un cajon llamado «Maestros» con once modulos
 * dentro —desde empresas hasta tipos de documento— y dos items sueltos entre
 * grupos; el dueño del producto: «me da la impresion que no todo va agrupado».
 */
return [
    'dashboard'          => 'Panel',

    // ── Trabajo en obra ───────────────────────────────────────────────────
    'group_field'        => 'Trabajo en obra',
    'work_plans'         => 'Planes de trabajo',
    // Solo la ve el super: plan, trabajador y la foto tomada al firmar.
    'signature_photos'   => 'Fotos de firmas',

    // ── Personas y empresas ───────────────────────────────────────────────
    // El cargo y el tipo de documento son atributos de una persona, y por eso
    // viven aqui y no con los catalogos de obra.
    'group_people'       => 'Personas y empresas',
    'people'             => 'Trabajadores',
    'companies'          => 'Empresas',
    'positions'          => 'Cargos',
    'document_types'     => 'Tipos de documento',

    // ── Documentos y reglas ───────────────────────────────────────────────
    // Que se le exige a un plan: los papeles y las reglas de quien los firma.
    // El nombre lo dio el dueño del producto: «'Documentos y Firmas' se
    // llamara 'Documentos y Reglas'».
    'group_docs'         => 'Documentos y Reglas',
    'form_templates'     => 'Documentos',
    'work_types'         => 'Tipos de trabajo',
    'approval_rules'     => 'Reglas de aprobación',
    'approver_roles'     => 'Roles aprobadores',

    // ── Lugares de trabajo ────────────────────────────────────────────────
    'group_places'       => 'Lugares de trabajo',
    'work_locations'     => 'Sedes',
    'workstations'       => 'Puestos de trabajo',
    'work_areas'         => 'Áreas',

    // ── Comunicación ──────────────────────────────────────────────────────
    'group_communication' => 'Comunicación',
    'messages'            => 'Mensajes',
    'inbox'               => 'Bandeja',

    // ── Automatizaciones (planes con la función activa) ───────────────────
    'group_automation'   => 'Automatizaciones',
    'automations'        => 'Automatizaciones',

    // ── Administración ────────────────────────────────────────────────────
    // Lo que administra el dueño del workspace. El historial se llamaba «Logs
    // del sistema», que es como lo llama un programador: quien lo abre busca
    // quien cambio algo, no un log.
    'group_admin'        => 'Administración',
    'users'              => 'Usuarios',
    'roles'              => 'Perfiles',
    'workspace'          => 'Ajustes del workspace',
    'audit_logs'         => 'Historial de cambios',

    // ── Configuración del sistema (solo super) ────────────────────────────
    'group_system'       => 'Configuración del sistema',
    'tenants'            => 'Workspaces',
    'plans'              => 'Planes',
    'system_modules'     => 'Módulos',
    'regions'            => 'Regiones',
    'countries'          => 'Países',
    'languages'          => 'Idiomas',
    'locales'            => 'Locales',
    'settings'           => 'Ajustes',

    // ── Modulos que NO tienen entrada de menu ─────────────────────────────
    //
    // Estas claves no pintan nada en el lateral: las lee `moduleLabel()` del
    // historial de cambios, que rotula cada modulo con `sidebar.<modulo>` y cae
    // al identificador crudo si falta. Sin ellas, la auditoria enseñaba
    // «form_submissions» en la cara del usuario.
    'form_submissions'   => 'Documentos llenados',
    // Era la entrada «Firmas por revisar» del menu; la bandeja se fue y la
    // clave se queda para rotular el modulo en el historial de cambios.
    'signature_events'   => 'Firmas',
    'customers'          => 'Clientes',
    'brands'             => 'Marcas',
    'permissions'        => 'Permisos',
];
