<?php

/*
|--------------------------------------------------------------------------
| Feature flags + plan limits — gating por suscripción (FALLBACK)
|--------------------------------------------------------------------------
|
| FUENTE DE VERDAD: la tabla `plans` (editable desde el módulo Plans del
| super). Este archivo es solo FALLBACK para edge-cases de migration
| order (cuando la tabla `plans` todavía no existe). Si cambia un plan,
| hágalo en el módulo Plans o en PlansSeeder — no aquí.
|
| 4 tiers:
|   free   → demo (1 user, 50 records, sin paid features)
|   basic  → profesional solo (1 user, 5k records, paid features SIN equipo)
|   pro    → equipo (10 users, 50k records, equipo + bulk + import + automations)
|   ent.   → empresa (∞ users, ∞ records, todo + API)
*/
return [

    'plans' => ['free', 'basic', 'pro', 'enterprise'],

    'default_plan' => 'free',

    /**
     * Features booleanas — qué planes desbloquean qué funcionalidad.
     *   null               = sin gating (todos los planes lo tienen)
     *   ['plan1', 'plan2']  = solo esos planes lo tienen
     */
    'features' => [
        // ─── Todos los planes ────────────────────────────────────────
        'export_csv'              => null,
        'basic_filters'           => null,
        'soft_delete_trash'       => null,

        // ─── Basic + Pro + Enterprise (paid tiers) ───────────────────
        'export_pdf'              => ['basic', 'pro', 'enterprise'],
        'export_excel'            => ['basic', 'pro', 'enterprise'],
        'export_word'             => ['basic', 'pro', 'enterprise'],
        'audit_log_view'          => ['basic', 'pro', 'enterprise'],
        'saved_views'             => ['basic', 'pro', 'enterprise'],

        // ─── Pro + Enterprise (orientado a equipo) ───────────────────
        // team_management gatea los modulos Users + Roles completos.
        // bulk/imports/automations tienen poco sentido con 1 user.
        'report_sharing'          => ['pro', 'enterprise'],
        'team_management'         => ['pro', 'enterprise'],
        'bulk_operations'         => ['pro', 'enterprise'],
        'imports'                 => ['pro', 'enterprise'],
        'edit_all'                => ['pro', 'enterprise'],
        'automations'             => ['pro', 'enterprise'],

        // ─── Enterprise only ─────────────────────────────────────────
        'api_access'              => ['enterprise'],
        'branded_exports'         => ['enterprise'],
        'scheduled_exports'       => ['enterprise'],
        'export_webhook_delivery' => ['enterprise'],
        'export_email_delivery'   => ['enterprise'],
        'extended_retention'      => ['enterprise'],
        'higher_export_rate_limit'=> ['enterprise'],
        // Restricción de usuarios a clientes específicos (3ª capa de acceso):
        // permite dar acceso a la gente del cliente para que vea SOLO su flota.
        'customer_scoped_users'   => ['enterprise'],
    ],

    /**
     * Nivel de soporte por plan — NO es una feature booleana, es un atributo
     * descriptivo de 3 niveles. Fuente de verdad: columna `plans.support_level`.
     * Esto es solo fallback para migration-order edge-cases.
     */
    'support_level' => [
        'free'       => 'community',
        'basic'      => 'email',
        'pro'        => 'email',
        'enterprise' => 'priority',
    ],

    /**
     * Límites numéricos por plan. PHP_INT_MAX = ilimitado.
     */
    'limits' => [
        'max_users' => [
            'free'       => 1,
            'basic'      => 1,
            'pro'        => 10,
            'enterprise' => PHP_INT_MAX,
        ],
        'max_records_per_module' => [
            'free'       => 50,
            'basic'      => 5_000,
            'pro'        => 50_000,
            'enterprise' => PHP_INT_MAX,
        ],
        'export_rate_limit' => [
            'free'       => 1,
            'basic'      => 3,
            'pro'        => 10,
            'enterprise' => 50,
        ],
    ],
];
