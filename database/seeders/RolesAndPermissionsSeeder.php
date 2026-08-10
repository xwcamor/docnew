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
        // form_submissions.sign: firmar un formato (con reconocimiento facial o
        // con captura por tiempo de espera). Separado del CRUD: se puede llenar
        // un formato sin poder firmarlo.
        // signature_events.review: resolver la bandeja de firmas que quedaron
        // pendientes de revision.
        // people.view_private_info: ver el documento de identidad completo, la
        // foto y la firma de una persona. Sin el, el DNI sale enmascarado
        // (******78) y la foto y la firma no se sirven. Es el
        // `users.display_private_info` del sistema anterior, pero por perfil en
        // vez de por usuario, que es como se conceden aqui los permisos.
        // people.view_media: ver y reemplazar la FOTO DE REFERENCIA y la FIRMA
        // guardadas de una persona, desde su ficha. Es el unico permiso que el
        // admin del workspace NO recibe por defecto: es material interno del
        // administrador del sistema, que es quien sube la foto buena cuando la
        // capturada en obra sale irreconocible. Un admin que de verdad lo
        // necesite lo tiene concediendoselo; no esta cerrado, esta cerrado POR
        // DEFECTO, que no es lo mismo.
        foreach (['comments.view', 'comments.create', 'comments.delete', 'form_submissions.sign', 'signature_events.review', 'people.view_private_info', 'people.view_media'] as $perm) {
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

        // admin: TODOS los permisos del sistema MENOS uno. Los módulos core
        // (tenants, regions, languages, etc.) no generan permissions a propósito
        // → admin nunca puede siquiera intentar asignarlos a sus roles. Ver
        // SystemModulesSeeder.
        //
        // La excepción es `people.view_media`: la foto de referencia y la firma
        // guardadas son material del administrador del sistema. El admin del
        // workspace sí ve la cara de quien firmó dentro de un plan —que es lo
        // que necesita para saber quién estuvo en obra— pero no el archivo de
        // cada persona. Si en algún workspace hace falta, se le concede.
        $admin->syncPermissions(Permission::all()->reject(fn ($p) => $p->name === 'people.view_media'));

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

        // Módulos de datos de negocio de DOCUFIZ. Los catálogos de obra
        // (work_types, positions, ...) se gestionan por rol, no por perfil.
        $bizModules = ['companies', 'people', 'work_plans', 'form_templates', 'form_submissions'];
        $noDelete   = ['view', 'show', 'create', 'edit', 'export', 'import']; // todo salvo delete

        // Supervisor de obra: arma el plan del dia, registra a sus trabajadores
        // y aprueba. No elimina nada ni toca el catalogo de documentos.
        $supervisorPerms = array_merge(
            $pick('work_plans',       $noDelete),
            $pick('people',           ['view', 'show', 'create', 'edit', 'export']),
            $pick('companies',        ['view', 'show']),
            $pick('form_submissions', $noDelete),
            $pick('form_templates',   ['view', 'show']),
            ['form_submissions.sign', 'signature_events.review', 'comments.view', 'comments.create'],
        );

        // Usuario de campo: llena los formatos del plan y firma. No crea planes
        // ni da de alta personas.
        $fieldPerms = array_merge(
            $pick('work_plans',       ['view', 'show']),
            $pick('form_submissions', ['view', 'show', 'create', 'edit']),
            $pick('people',           ['view', 'show']),
            ['form_submissions.sign', 'comments.view', 'comments.create'],
        );

        // Auditor HSE: lo ve todo y lo exporta, no escribe nada.
        $auditorParts = array_map(fn ($m) => $pick($m, ['view', 'show', 'export']), $bizModules);
        $auditorParts[] = ['signature_events.review', 'comments.view'];
        $auditorPerms = array_merge(...$auditorParts);

        // Soporte: crea y edita cualquier dato, no elimina.
        $editorParts = array_map(fn ($m) => $pick($m, $noDelete), $bizModules);
        $editorParts[] = ['comments.view', 'comments.create'];
        $editorPerms = array_merge(...$editorParts);

        $profiles = [
            'Supervisor de obra' => [
                'desc'  => 'Arma el plan de trabajo del dia, registra a sus trabajadores, llena y firma los documentos, y revisa las firmas que quedaron pendientes. No elimina registros.',
                'perms' => $supervisorPerms,
            ],
            'Usuario de campo' => [
                'desc'  => 'Llena y firma los formatos del plan al que esta asignado. No crea planes ni da de alta personas.',
                'perms' => $fieldPerms,
            ],
            'Auditor HSE (solo lectura)' => [
                'desc'  => 'Consulta y exporta todo: planes, formatos, personas y evidencias de firma. No modifica nada.',
                'perms' => $auditorPerms,
            ],
            'Soporte (editor)' => [
                'desc'  => 'Crea y edita cualquier dato de negocio pero NO elimina.',
                'perms' => $editorPerms,
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
        ];

        foreach ($assignments as $email => $role) {
            $userModel = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($userModel) {
                $userModel->syncRoles([$role]);
                $this->command?->info("  · {$email}  →  {$role->name}");
            } else {
                // Este sembrador corre dos veces a proposito: la primera define
                // permisos y roles, la segunda —ya con UsersSeeder detras— los
                // asigna. Que en la primera no haya usuarios es lo esperado, y
                // avisarlo como si fuera un problema solo asusta al que instala.
                $this->command?->line("  · {$email}  aun no existe; se le asigna en la segunda pasada.");
            }
        }

        // Workers (no-admin) de Empresa 1 y 2: cada uno con un perfil GLOBAL
        // distinto para probar visualmente los permisos. En la 1ª pasada (antes
        // de UsersSeeder) no existen y se omiten; en la 2ª se asignan.
        $workerAssignments = [
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
