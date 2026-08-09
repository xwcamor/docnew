<?php

return [
    'title'      => 'Panel',
    'hello'      => 'Hola, :name',
    'user'       => 'usuario',
    'role_super' => 'Panel de plataforma',
    'role_admin' => 'Panel del día',
    'role_user'  => 'Panel del día',

    // ── El panel del día (obra) ───────────────────────────────────────────
    'today_title'      => 'Hoy en la obra',
    'today_subtitle'   => 'Los planes con fecha de hoy y lo que le falta a cada uno.',
    'today_all_scope'  => 'Estás viendo todos los workspaces.',
    'plans_title'      => 'Planes de hoy',
    'plans_none'       => 'No hay ningún plan con fecha de hoy.',
    'plans_none_hint'  => 'Cuando se registre un plan para hoy aparecerá aquí con lo que le falta.',
    'plans_new'        => 'Nuevo plan',
    'plans_see_all'    => 'Ver todos los de hoy',
    'plans_more'       => 'y :count más con fecha de hoy',
    'plan_missing'     => 'Falta',
    'plan_no_company'  => 'Sin empresa',
    'plan_no_location' => 'Sin sede',

    // Indicadores del panel del día
    'widget_plans_today'        => 'Planes de hoy',
    'widget_signatures_pending' => 'Firmas pendientes',
    'widget_forms_pending'      => 'Formatos sin confirmar',
    'widget_plans_open'         => 'Planes sin terminar',
    'widget_signatures_review'  => 'Firmas por revisar',

    'hint_plans_today' => '{0} Todos terminados|{1} :count sin terminar|[2,*] :count sin terminar',
    'hint_signatures_pending' => ':workers de trabajadores · :approvals de aprobación',
    'hint_forms_pending'      => 'Entregados y todavía en borrador',
    'hint_plans_open'         => 'De cualquier fecha',
    'hint_signatures_review'  => 'Se capturaron sin reconocer la cara',

    // Estado de un plan (color + palabra, nunca solo el color)
    'plan_state_done'    => 'Terminado',
    'plan_state_closed'  => 'Cerrado',
    'plan_state_pending' => 'En curso',

    // ── Tu workspace (admin del tenant) ───────────────────────────────────
    'workspace_title'    => 'Tu workspace',
    'workspace_subtitle' => 'La cuenta, no la obra.',

    'widget_users_count'    => 'Usuarios',
    'widget_automations'    => 'Automatizaciones activas',
    'widget_auto_failures'  => 'Fallos de automatización',
    'widget_plan_days_left' => 'Días de plan',

    'hint_users_count'   => 'En tu workspace',
    'hint_automations'   => 'Reglas que se ejecutan solas',
    'hint_auto_failures' => 'En los últimos 7 días',

    // ── Plataforma (super) ────────────────────────────────────────────────
    'platform_title'    => 'Plataforma',
    'platform_subtitle' => 'El estado del sistema completo.',

    'widget_tenants_active' => 'Workspaces activos',
    'widget_subs_active'    => 'Suscripciones activas',
    'widget_subs_expiring'  => 'Por vencer',
    'widget_autos_runs_24h' => 'Automatizaciones (24 h)',

    'hint_tenants_total' => ':count en total',
    'hint_subs_active'   => 'En curso',
    'hint_subs_expiring' => 'En 7 días',
    'hint_autos_failed'  => '{0} Ninguna falló|{1} :count falló|[2,*] :count fallaron',

    'expiring_soon'      => 'Suscripciones por vencer',
    'recent_automations' => 'Automatizaciones recientes',
    'no_automations_yet' => 'Todavía no hay ejecuciones de automatizaciones.',
    'days_left'          => ':n días',
    'records_processed'  => 'Registros procesados',

    // ── Sin permiso para ver planes ───────────────────────────────────────
    'welcome_title' => 'Bienvenido',
    'welcome_body'  => 'Desde el menú lateral entras a los módulos a los que tienes acceso. El panel del día aparece aquí en cuanto tu perfil pueda ver los planes de trabajo.',
];
