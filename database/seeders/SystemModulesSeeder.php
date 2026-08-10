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
            // Marcas. Faltaba, y no era inofensivo: `routes/business_management.php`
            // gatea las TRECE rutas del modulo con `permission:brands.*`, y esos
            // permisos no los creaba nadie. Resultado: cualquiera que no sea
            // super recibia 403 en el modulo entero, y ningun admin podia
            // delegarlo a un perfil porque los permisos no existian ni para
            // asignarlos. El super entraba solo por el bypass de `Gate::before`.
            ['name' => 'Brands',         'permission_key' => 'brands'],

            // DOCUFIZ — módulos del dominio de documentación de seguridad en obra.
            ['name' => 'Companies',      'permission_key' => 'companies'],
            ['name' => 'People',         'permission_key' => 'people'],
            ['name' => 'WorkPlans',      'permission_key' => 'work_plans'],
            ['name' => 'FormTemplates',  'permission_key' => 'form_templates'],
            ['name' => 'FormSubmissions','permission_key' => 'form_submissions'],
            ['name' => 'SignatureEvents','permission_key' => 'signature_events'],

            // Catálogos de obra.
            ['name' => 'WorkTypes',      'permission_key' => 'work_types'],
            ['name' => 'WorkLocations',  'permission_key' => 'work_locations'],
            ['name' => 'Workstations',   'permission_key' => 'workstations'],
            ['name' => 'WorkAreas',      'permission_key' => 'work_areas'],
            ['name' => 'Positions',      'permission_key' => 'positions'],
            ['name' => 'DocumentTypes',  'permission_key' => 'document_types'],
            // El flujo de aprobacion: las reglas y el catalogo de quien firma.
            // El observer crea los 7 permisos de cada uno al insertar la fila.
            ['name' => 'ApprovalRules',  'permission_key' => 'approval_rules'],
            ['name' => 'ApproverRoles',  'permission_key' => 'approver_roles'],
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
