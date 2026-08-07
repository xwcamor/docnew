<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * ExampleTenantRolesSeeder — LIMPIEZA de perfiles per-tenant de demostración.
 *
 * Antes este seeder creaba perfiles tenant-scoped (Customer Editor/Viewer en
 * Empresa 1 y 2) y se los asignaba a los workers. Eso quedó OBSOLETO: ahora los
 * perfiles son GLOBALES (los publica el super, los ven y asignan todos los
 * workspaces) y se definen + asignan en RolesAndPermissionsSeeder.
 *
 * Este seeder solo BORRA los perfiles per-tenant de demo que pudieran haber
 * quedado de seeds anteriores, para que un re-seed (sin migrate:fresh) no deje
 * basura. En una BD fresca no hay nada que borrar → no-op.
 *
 * Idempotente. NO crea roles ni reasigna usuarios.
 */
class ExampleTenantRolesSeeder extends Seeder
{
    public function run(): void
    {
        // Perfiles de demo per-tenant (tenant_id no null) de versiones previas.
        $demoNames = [
            'Customer Editor', 'Customer Viewer',
            'Reception', 'Accountant', 'Soporte', 'Editor', 'Visitante',
        ];

        $demo = Role::whereIn('name', $demoNames)
            ->whereNotNull('tenant_id')
            ->get();

        foreach ($demo as $r) {
            DB::table('role_has_permissions')->where('role_id', $r->id)->delete();
            DB::table('model_has_roles')->where('role_id', $r->id)->delete();
            $r->delete();
        }

        if ($demo->isNotEmpty()) {
            $this->command?->info('Perfiles per-tenant de demo eliminados: ' . $demo->pluck('name')->unique()->implode(', '));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
