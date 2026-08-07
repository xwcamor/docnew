<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

/**
 * permissions:export — dumpea los permisos NO canónicos a JSON.
 *
 * Los permisos canónicos (read/create/edit/delete/show/export/restore/
 * force_delete/audit) se generan automáticamente por SystemModuleObserver
 * cuando se crea un SystemModule, así que no necesitan persistirse aquí.
 *
 * Los permisos custom (los que el super agrega desde la UI de
 * SystemModules → "agregar acción") sí necesitan persistirse para
 * sobrevivir a `migrate:fresh`. Este comando los exporta a
 * `database/seeders/data/custom_permissions.json`, que después lee el
 * CustomPermissionsSeeder.
 *
 * Workflow recomendado:
 *   1. Super agrega permisos custom desde la UI cuando los necesita.
 *   2. Cada cierto tiempo (o antes de un deploy a producción) corre
 *      `php artisan permissions:export` para snapshot.
 *   3. El JSON queda commiteado en el repo.
 *   4. Próximo `migrate:fresh --seed` repuebla todo desde ese JSON.
 */
class ExportCustomPermissions extends Command
{
    protected $signature = 'permissions:export {--path= : Path destino del JSON}';
    protected $description = 'Exporta los permisos custom (no canónicos) a un JSON seedeable';

    /** Acciones canónicas que el SystemModuleObserver crea automáticamente. */
    public const CANONICAL_ACTIONS = [
        'read', 'create', 'edit', 'delete', 'show',
        'export', 'restore', 'force_delete', 'audit',
    ];

    public function handle(): int
    {
        $path = $this->option('path') ?? database_path('seeders/data/custom_permissions.json');

        // Permisos con guard 'web' que NO terminen en una acción canónica.
        $custom = Permission::where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name', 'guard_name'])
            ->filter(function (Permission $p) {
                $parts = explode('.', $p->name);
                if (count($parts) < 2) return true; // permisos sin formato modulo.accion → custom
                $action = end($parts);
                return !in_array($action, self::CANONICAL_ACTIONS, true);
            })
            ->map(fn (Permission $p) => [
                'name'       => $p->name,
                'guard_name' => $p->guard_name,
            ])
            ->values()
            ->all();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Exportados " . count($custom) . " permisos custom a {$path}");
        return self::SUCCESS;
    }
}
