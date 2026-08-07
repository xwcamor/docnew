<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Common actions per module — fuente única en el Observer.
        $actions = \App\Observers\SystemModuleObserver::CANONICAL_ACTIONS;

        // Read modules from system_modules table
        $modules = DB::table('system_modules')->whereNull('deleted_at')->get();

        // Generate permissions dynamically
        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name'       => "{$module->permission_key}.{$action}",
                    'guard_name' => 'web',
                ]);
            }
        }

        // Permisos transversales que NO salen de un módulo de system_modules.
        // Comentarios de usuario sobre transformadores y muestras: el admin decide
        // qué perfiles pueden ver/crear/borrar comentarios.
        // transformers.samples: cargar/editar MUESTRAS de ensayos sin poder tocar
        // la ficha del trafo (perfiles tipo "Cliente Editor"). transformers.edit
        // lo incluye vía OR en las rutas.
        // diagnosis_notes.create: ESCRIBIR la "Nota del diagnosticador" (hilo a
        // nivel del transformer). Separado de comments.create — que ahora solo
        // habilita los comentarios POR MUESTRA — para que un cargador de muestras
        // pueda comentar SU muestra sin firmar la nota del especialista. Ver/borrar
        // siguen compartiendo comments.view/comments.delete (no se separaron).
        foreach (['comments.view', 'comments.create', 'comments.delete', 'transformers.samples', 'diagnosis_notes.create'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // ─── Roles ────────────────────────────────────────────────────────
        $superAdmin = Role::updateOrCreate(
            ['name' => 'super', 'guard_name' => 'web'],
            ['description' => 'Acceso total al sistema (bypass via Gate::before)']
        );

        $admin = Role::updateOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['description' => 'Administrador de cliente']
        );

        // 'api' role — assigned to the invisible system user that holds API tokens.
        // Permissions are NOT attached at the role level here because each token
        // carries its own ability list (Sanctum abilities). The role just lets us
        // identify and hide these users in lists.
        Role::updateOrCreate(
            ['name' => 'api', 'guard_name' => 'web'],
            ['description' => 'Usuario interno para tokens de API (no logueable)']
        );

        // super: Gate::before bypass + sync all (consistency con policy checks).
        $superAdmin->syncPermissions(Permission::all());

        // admin: TODOS los permisos del sistema. Los módulos core (tenants, regions,
        // languages, etc.) no generan permissions a propósito → admin nunca puede
        // siquiera intentar asignarlos a sus roles. Ver SystemModulesSeeder.
        $admin->syncPermissions(Permission::all());

        // ─── 4 PERFILES GLOBALES (plantillas que publica el super) ──────────
        // tenant_id null = globales → TODOS los workspaces los ven y los asignan
        // a sus usuarios; solo-lectura para los admin, solo el super los edita.
        // Borra los perfiles globales de versiones previas (se reemplazan).
        Role::whereNull('tenant_id')
            ->whereIn('name', [
                'cliente_lectura', 'cliente_editor',
                'Gestor de clientes', 'Visualizador de transformadores',
                'Administrador de transformadores', 'Técnico de laboratorio',
                'Gestor de catálogos',
            ])
            ->get()
            ->each(function ($r) {
                DB::table('role_has_permissions')->where('role_id', $r->id)->delete();
                DB::table('model_has_roles')->where('role_id', $r->id)->delete();
                $r->delete();
            });

        // Helpers para armar sets de permisos por módulo.
        $canon = \App\Observers\SystemModuleObserver::CANONICAL_ACTIONS; // view,show,create,edit,delete,export,import
        $all   = fn (string $k) => array_map(fn ($a) => "{$k}.{$a}", $canon);
        $pick  = fn (string $k, array $acts) => array_map(fn ($a) => "{$k}.{$a}", $acts);
        $grant = function (Role $role, array $names) {
            $role->syncPermissions(
                Permission::whereIn('name', $names)->where('guard_name', 'web')->get()
            );
        };

        // Módulos de DATOS de negocio (excluye oil_types/transformer_types/
        // tap_changer_types: son globales super-only y no se ofrecen a perfiles).
        $bizModules = ['customers', 'transformers', 'brands', 'laboratories',
            'tap_changer_brands', 'tap_changer_models', 'tap_changer_technologies'];
        $noDelete   = ['view', 'show', 'create', 'edit', 'export', 'import']; // todo salvo delete

        // Soporte (editor): crea/edita cualquier dato de negocio, NO elimina.
        // Incluye diagnosis_notes.create: es un rol de especialista, sí firma la
        // "Nota del diagnosticador" (además de comentar muestras).
        $editorParts = array_map(fn ($m) => $pick($m, $noDelete), $bizModules);
        $editorParts[] = ['transformers.samples', 'comments.view', 'comments.create', 'diagnosis_notes.create'];
        $editorPerms = array_merge(...$editorParts);

        // Soporte (editor full): todo sobre los datos de negocio (incl. eliminar).
        // NO incluye users/roles: el super y el admin ya tienen acceso a esos
        // módulos por su rol; no se delegan vía un perfil custom.
        $fullParts   = array_map(fn ($m) => $all($m), $bizModules);
        $fullParts[] = ['transformers.samples', 'comments.view', 'comments.create', 'comments.delete', 'diagnosis_notes.create'];
        $fullPerms   = array_merge(...$fullParts);

        // Reglas de diagnóstico y logs del sistema NO hace falta excluirlos: sus
        // menús son hasRole(super|admin), así que un perfil CUSTOM nunca los ve.
        $profiles = [
            'Empresa (solo lectura)' => [
                'desc'  => 'Solo lectura: ve el dashboard y los transformadores con sus diagnósticos. No crea, edita ni elimina nada.',
                'perms' => array_merge($pick('transformers', ['view', 'show', 'export']), ['comments.view']),
            ],
            'Empresa (carga de muestras)' => [
                'desc'  => 'Ve el dashboard y los transformadores; no edita ni elimina trafos, pero carga muestras de ensayo y comenta SUS muestras. Lee la nota del diagnosticador pero NO la escribe (eso es del especialista).',
                // comments.view + comments.create (comentar SUS muestras), pero SIN
                // diagnosis_notes.create: la "Nota del diagnosticador" la firma el
                // especialista, no quien carga muestras.
                'perms' => array_merge($pick('transformers', ['view', 'show', 'export']), ['transformers.samples', 'comments.view', 'comments.create']),
            ],
            'Soporte (editor)' => [
                'desc'  => 'Crea y edita cualquier dato (clientes, transformadores, catálogos) pero NO elimina. Sin reglas de diagnóstico ni logs.',
                'perms' => $editorPerms,
            ],
            'Soporte (editor full)' => [
                'desc'  => 'Gestión completa de los datos de negocio: crea, edita y elimina clientes, transformadores y catálogos. No gestiona accesos (usuarios/perfiles) ni ve reglas de diagnóstico o logs.',
                'perms' => $fullPerms,
            ],
        ];

        $globalRoles = [];
        foreach ($profiles as $name => $cfg) {
            $role = Role::updateOrCreate(
                ['name' => $name, 'guard_name' => 'web', 'tenant_id' => null],
                ['description' => $cfg['desc'], 'is_active' => true]
            );
            $grant($role, $cfg['perms']);
            $globalRoles[$name] = $role;
        }

        // ─── Assign roles to seeded users by email ────────────────────────
        // Workers (jose/pedro/luis/ana) quedan SIN rol por diseño. El admin de
        // cada tenant les asigna un perfil custom (Soporte/Editor/Visitante,
        // ver ExampleTenantRolesSeeder) cuando arme su equipo.
        $assignments = [
            'super@example.com' => $superAdmin,  // platform owner
            'joe@example.com'             => $admin,        // Empresa 1 admin
            'yugi@example.com'             => $admin,        // Empresa 2 admin
            'independiente@example.com'     => $admin,        // Independiente (admin de su propio workspace)
        ];

        foreach ($assignments as $email => $role) {
            $userModel = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($userModel) {
                $userModel->syncRoles([$role]);
                $this->command?->info("  · {$email}  →  {$role->name}");
            } else {
                $this->command?->warn("  · {$email}  NOT FOUND (run UsersSeeder first)");
            }
        }

        // Workers (no-admin) de Empresa 1 y 2: cada uno con un perfil GLOBAL
        // distinto para probar visualmente los permisos. En la 1ª pasada (antes
        // de UsersSeeder) no existen y se omiten; en la 2ª se asignan.
        $workerAssignments = [
            'jose@example.com'  => 'Empresa (solo lectura)',       // Empresa 1
            'pedro@example.com' => 'Empresa (carga de muestras)',  // Empresa 1
            'luis@example.com'  => 'Soporte (editor)',             // Empresa 2
            'ana@example.com'   => 'Soporte (editor full)',        // Empresa 2
        ];
        foreach ($workerAssignments as $email => $roleName) {
            $userModel = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($userModel && isset($globalRoles[$roleName])) {
                $userModel->syncRoles([$globalRoles[$roleName]]);
                $this->command?->info("  · {$email}  →  {$roleName}");
            }
        }

        $this->command?->info('Permissions: ' . Permission::count() . '. Roles: ' . Role::count() . '.');
    }
}
