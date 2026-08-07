<?php

/*
|--------------------------------------------------------------------------
| Soft-delete purge policy per module
|--------------------------------------------------------------------------
|
| Define after how many days each module's soft-deleted records become
| eligible for hard-delete. Records younger than `days` are kept; older
| are physically removed when `app:purge-soft-deleted` runs.
|
| Per-module options:
|   - model:    FQCN of the Eloquent model (must use SoftDeletes)
|   - days:     grace window in days (counted since deleted_at)
|   - anonymize: if true, replaces PII fields with random data BEFORE
|                deleting (relevant for users/patients with sensitive cols).
|                List the columns to anonymize.
|   - chunk:    batch size for the delete (default 500). Tune for tables
|               with very large rows.
|
| Adjust these by use-case:
|   - Catalog data (regions, languages, countries): days = 365
|   - Transactional data (tenants, settings):       days = 90
|   - PII data (users, patients):                   days = 30 + anonymize
|   - Time-series append-only:                      days = configurable per business
|
| Set days = 0 in env-specific overrides to disable purging for a module.
*/

return [
    'modules' => [
        'regions' => [
            'model' => \App\Models\Region::class,
            'days'  => 365,
        ],
        'languages' => [
            'model' => \App\Models\Language::class,
            'days'  => 365,
        ],
        'countries' => [
            'model' => \App\Models\Country::class,
            'days'  => 365,
        ],
        'locales' => [
            'model' => \App\Models\Locale::class,
            'days'  => 365,
        ],
        'tenants' => [
            'model' => \App\Models\Tenant::class,
            'days'  => 90,
        ],
        'system_modules' => [
            'model' => \App\Models\SystemModule::class,
            'days'  => 365,
        ],
        'users' => [
            'model'     => \App\Models\User::class,
            'days'      => 30,
            // PII completa: nombre, email, foto, google_id + password (hash).
            // El campo `module_tours` es jsonb sin PII per-se, no se anonimiza.
            'anonymize' => ['name', 'email', 'photo', 'signature', 'google_id', 'password'],
        ],
        // ─── Negocio per-tenant ─────────────────────────────────────────
        'customers' => [
            'model'     => \App\Models\Customer::class,
            'days'      => 90,
            // Customer puede ser una persona física — anonimizamos identificadores.
            // `cod` es código interno del tenant pero puede contener DNI/CUIT/etc.
            'anonymize' => ['name', 'cod'],
        ],
        'roles' => [
            'model' => \App\Models\Role::class,
            'days'  => 180,
            // No PII — roles son metadata. Solo retention para mantener trazabilidad.
        ],
        'automations' => [
            'model' => \App\Models\Automation::class,
            'days'  => 90,
            // trigger_config / action_config pueden contener emails de destinatarios.
            // Anonimizamos action_config (json) reemplazando con marker; description
            // queda como string anodino. No usamos lista plana porque son jsonb.
            'anonymize' => ['description'],
        ],
        // ─── Catálogo de billing ────────────────────────────────────────
        'plans' => [
            'model' => \App\Models\Plan::class,
            'days'  => 365,
        ],
        'subscriptions' => [
            'model' => \App\Models\Subscription::class,
            'days'  => 365,
            // History de pagos — retención larga (audit + reportes históricos).
        ],
        'settings' => [
            'model' => \App\Models\Setting::class,
            'days'  => 365,
        ],
        'oil_types' => [
            'model' => \App\Models\OilType::class,
            'days'  => 90,
        ],
        'transformers' => [
            'model' => \App\Models\Transformer::class,
            'days'  => 90,
        ],
        'transformer_types' => [
            'model' => \App\Models\TransformerType::class,
            'days'  => 90,
        ],
        'brands' => [
            'model' => \App\Models\Brand::class,
            'days'  => 90,
        ],
        'tap_changer_types' => [
            'model' => \App\Models\TapChangerType::class,
            'days'  => 90,
        ],
        'laboratories' => [
            'model' => \App\Models\Laboratory::class,
            'days'  => 90,
        ],
        'tap_changer_brands' => [
            'model' => \App\Models\TapChangerBrand::class,
            'days'  => 90,
        ],
        'tap_changer_models' => [
            'model' => \App\Models\TapChangerModel::class,
            'days'  => 90,
        ],
        'tap_changer_technologies' => [
            'model' => \App\Models\TapChangerTechnology::class,
            'days'  => 90,
        ],
        // Suma modulos nuevos aqui:
        // 'patients' => [...],
        // 'doctors'  => [...],
        // 'transformers' => [...],
    ],
];
