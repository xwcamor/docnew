<?php

/*
|--------------------------------------------------------------------------
| Polymorphic allowlist — single source of truth
|--------------------------------------------------------------------------
|
| Mapea slug-de-módulo → FQCN del modelo. Lo usan:
|   - FavoriteController (toggle de favoritos polimórfico)
|   - RecentViewController (track de "últimos vistos")
|   - HandleInertiaRequests (resolver el route show del módulo)
|
| Centralizado aqui para evitar que el slug "regions" se hardcodee en 3
| lugares. Cuando agregues un modulo nuevo (patients, doctors, etc.),
| agréguelo SOLO aqui y queda habilitado en todos los lugares de una vez.
|
| ESQUEMA por modulo:
|   - model:     FQCN del Eloquent model
|   - show_route: nombre del route name del show (para Recientes)
|
| El allowlist nunca debe aceptar entradas dinamicas del cliente — todo
| modulo soportado tiene que estar declarado aqui explicitamente.
*/
return [
    'modules' => [
        'regions' => [
            'model'      => \App\Models\Region::class,
            'show_route' => 'system_management.regions.show',
        ],
        'languages' => [
            'model'      => \App\Models\Language::class,
            'show_route' => 'system_management.languages.show',
        ],
        'countries' => [
            'model'      => \App\Models\Country::class,
            'show_route' => 'system_management.countries.show',
        ],
        'locales' => [
            'model'      => \App\Models\Locale::class,
            'show_route' => 'system_management.locales.show',
        ],
        'tenants' => [
            'model'      => \App\Models\Tenant::class,
            'show_route' => 'system_management.tenants.show',
        ],
        'system_modules' => [
            'model'      => \App\Models\SystemModule::class,
            'show_route' => 'system_management.system_modules.show',
        ],
        'users' => [
            'model'      => \App\Models\User::class,
            'show_route' => 'auth_management.users.show',
        ],
        'roles' => [
            'model'      => \App\Models\Role::class,
            'show_route' => 'user_management.roles.show',
        ],
        'customers' => [
            'model'      => \App\Models\Customer::class,
            'show_route' => 'business_management.customers.show',
        ],
        'automations' => [
            'model'      => \App\Models\Automation::class,
            'show_route' => 'automation_management.automations.show',
        ],
        'companies' => [
            'model'      => \App\Models\Company::class,
            'show_route' => 'business_management.companies.show',
        ],
        'people' => [
            'model'      => \App\Models\Person::class,
            'show_route' => 'business_management.people.show',
        ],
        'work_plans' => [
            'model'      => \App\Models\WorkPlan::class,
            'show_route' => 'business_management.work_plans.show',
        ],
        'form_templates' => [
            'model'      => \App\Models\FormTemplate::class,
            'show_route' => 'business_management.form_templates.show',
        ],
        'approver_roles' => [
            'model'      => \App\Models\ApproverRole::class,
            'show_route' => 'business_management.approver_roles.show',
        ],
        'approval_rules' => [
            'model'      => \App\Models\ApprovalRule::class,
            'show_route' => 'business_management.approval_rules.show',
        ],
        // Agrega modulos nuevos aqui cuando crees patients, doctors, etc.
    ],
];
