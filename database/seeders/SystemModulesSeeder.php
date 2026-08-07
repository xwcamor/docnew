<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemModulesSeeder extends Seeder
{
    public function run(): void
    {
        // Módulos que admin_empresarial puede asignar a sus roles.
        // Cada entry produce 7 permissions canónicas (view, show, create, edit,
        // delete, export, import) vía SystemModuleObserver::CANONICAL_ACTIONS.
        //
        // Los módulos CORE (system_modules, tenants, regions, languages, countries,
        // locales, settings) NO se registran aquí:
        //   1. Sus rutas están protegidas por `role:super` middleware, no por `permission:*`.
        //   2. super tiene Gate::before bypass — pasa toda check sin verificar.
        //   3. admin_empresarial nunca debe asignar esas permissions a sus roles.
        // → Crear esas permissions sería poblar rows fantasma que no gating nada.
        //
        // Importante: usamos Eloquent (`SystemModule::firstOrCreate`) en lugar de
        // `DB::table()->updateOrInsert` para que el Observer dispare y cree las
        // permissions canónicas automáticamente al inserción.
        // NOTA: `audit_logs` y `dashboards` NO se registran:
        //   - audit_logs: read-only cross-cutting, gated por `role:super|admin`
        //     en routes. No tiene CRUD → no necesita permissions.
        //   - dashboards: landing post-login, gated solo por `auth`. Cualquier user
        //     autenticado entra (incluso sin rol).
        // Si en el futuro admin necesita delegar acceso a audit a un rol custom
        // (ej: "Auditor"), agrega `audit_logs` aquí y se generan las permissions.
        $modules = [
            // Módulos accesibles por admin_empresarial (CRUD real).
            ['name' => 'Users',          'permission_key' => 'users'],
            ['name' => 'Roles',          'permission_key' => 'roles'],

            // Customers — primer módulo de negocio real. Generado con make:module
            // como patrón clonable para los siguientes (Patients, Inventory, etc.).
            ['name' => 'Customers',      'permission_key' => 'customers'],

            // TR APP — módulos del dominio de diagnóstico de transformadores.
            // (transformers/oil_types se registraban ad-hoc; aquí quedan
            // reproducibles para installs frescos. firstOrCreate = idempotente.)
            ['name' => 'Transformers',     'permission_key' => 'transformers'],
            ['name' => 'OilTypes',         'permission_key' => 'oil_types'],
            ['name' => 'TransformerTypes', 'permission_key' => 'transformer_types'],
            ['name' => 'Brands',           'permission_key' => 'brands'],
            ['name' => 'TapChangerTypes',  'permission_key' => 'tap_changer_types'],
            ['name' => 'TapChangerTechnologies', 'permission_key' => 'tap_changer_technologies'],
            ['name' => 'TapChangerModels', 'permission_key' => 'tap_changer_models'],
            ['name' => 'TapChangerBrands', 'permission_key' => 'tap_changer_brands'],
            ['name' => 'Laboratories',     'permission_key' => 'laboratories'],
        ];

        foreach ($modules as $m) {
            \App\Models\SystemModule::firstOrCreate(
                ['permission_key' => $m['permission_key']],
                [
                    'slug'       => Str::random(22),
                    'name'       => $m['name'],
                    'created_by' => 1,
                ]
            );
        }
    }
}
