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
        'transformer_types' => [
            'model'      => \App\Models\TransformerType::class,
            'show_route' => 'business_management.transformer_types.show',
        ],
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
        'oil_types' => [
            'model'      => \App\Models\OilType::class,
            'show_route' => 'business_management.oil_types.show',
        ],
        'transformers' => [
            'model'      => \App\Models\Transformer::class,
            'show_route' => 'business_management.transformers.show',
        ],
        'brands' => [
            'model'      => \App\Models\Brand::class,
            'show_route' => 'business_management.brands.show',
        ],
        'tap_changer_types' => [
            'model'      => \App\Models\TapChangerType::class,
            'show_route' => 'business_management.tap_changer_types.show',
        ],
        'laboratories' => [
            'model'      => \App\Models\Laboratory::class,
            'show_route' => 'business_management.laboratories.show',
        ],
        'tap_changer_brands' => [
            'model'      => \App\Models\TapChangerBrand::class,
            'show_route' => 'business_management.tap_changer_brands.show',
        ],
        'tap_changer_models' => [
            'model'      => \App\Models\TapChangerModel::class,
            'show_route' => 'business_management.tap_changer_models.show',
        ],
        'tap_changer_technologies' => [
            'model'      => \App\Models\TapChangerTechnology::class,
            'show_route' => 'business_management.tap_changer_technologies.show',
        ],
        // Agrega modulos nuevos aqui cuando crees patients, doctors, etc.
    ],
];
