<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Repuebla los permisos custom desde el snapshot JSON.
 *
 * Lee `database/seeders/data/custom_permissions.json` (generado por el
 * comando `permissions:export`) y crea los permisos que no existan todavía.
 * Idempotente: re-correr el seeder no duplica permisos.
 *
 * Los permisos canónicos NO los maneja este seeder — los crea el
 * SystemModuleObserver cuando el SystemModulesSeeder los inserta.
 */
class CustomPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/custom_permissions.json');

        if (!File::exists($path)) {
            $this->command?->warn("No existe {$path} — sin permisos custom para seedear.");
            return;
        }

        $permissions = json_decode(File::get($path), true);
        if (!is_array($permissions) || empty($permissions)) {
            $this->command?->info('custom_permissions.json vacío — nada para seedear.');
            return;
        }

        $created = 0;
        $existed = 0;
        foreach ($permissions as $row) {
            $name  = $row['name']       ?? null;
            $guard = $row['guard_name'] ?? 'web';
            if (!$name) continue;

            $perm = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => $guard]
            );
            $perm->wasRecentlyCreated ? $created++ : $existed++;
        }

        // Invalidamos cache de Spatie para que el cambio sea inmediato.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Custom permissions: {$created} creados, {$existed} ya existían.");
    }
}
