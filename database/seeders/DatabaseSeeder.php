<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Master seeder.
 *
 * Order matters because of foreign keys:
 *   1. Languages       → no FK
 *   2. Regions         → no FK to other seeded tables
 *   3. Locales         → needs language_id
 *   4. Countries       → needs region_id + default_locale_id
 *   5. SystemModules   → no FK
 *   6. RolesAndPermissions (1st pass) → needs system_modules. Creates roles
 *      (super, admin, user, api) + permissions. User assignments warn
 *      because users don't exist yet — that's expected on this pass.
 *   7. Tenants         → creates workspaces + auto-creates a "system user"
 *      per workspace (needs roles + countries + locales).
 *   8. Users           → creates the human users (super, joe, jose, etc.).
 *   9. RolesAndPermissions (2nd pass) → assigns role to each seeded user by email.
 *
 * Para data fake de prueba (benchmarking), ver el bloque comentado al final de
 * RegionsSeeder.php — 1000 regiones generadas con nombres realistas.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // ── Master / catalog data ───────────────────────────────────
            LanguagesSeeder::class,
            RegionsSeeder::class,
            LocalesSeeder::class,
            CountriesSeeder::class,

            // ── Tipos de documento (DNI, CE, PTP, Pasaporte) ────────────
            //    Necesita los paises. Sin esto la ficha de una persona se cae
            //    a la lista vieja escrita en PHP, que es lo que se quito.
            DocumentTypesSeeder::class,

            SystemModulesSeeder::class,

            // ── Pricing tiers (free/basic/pro/enterprise) ───────────────
            PlansSeeder::class,

            // ── Settings globales (25 keys: app, features, downloads,
            //    notifications, security, exports, uploads, audit, bulk).
            //    Critical: muchos servicios leen Setting::get(...) y sin
            //    estos defaults el comportamiento cae a hardcode o falla.
            SettingsSeeder::class,

            // ── Roles + permissions (1st pass — definitions only) ──────
            RolesAndPermissionsSeeder::class,

            // ── Tenants + per-tenant system user ────────────────────────
            TenantsSeeder::class,

            // ── Human users ─────────────────────────────────────────────
            UsersSeeder::class,

            // ── Roles + permissions (2nd pass — assigns roles to users) ─
            RolesAndPermissionsSeeder::class,

            // ── Custom permissions: los que el super creó desde la UI
            //    (SystemModules → agregar acción). Repuebla desde snapshot
            //    JSON. Idempotente: si están todos, no hace nada.
            CustomPermissionsSeeder::class,

            // ── Suscripcion del unico workspace: Empresa 1 → enterprise.
            //    El plan se deriva de la suscripcion vigente.
            ExampleSubscriptionsSeeder::class,

            // Aqui iban los clientes, transformadores y muestras de TRAFODEX.
            // Se quitaron: son datos de otro sistema —344 empresas con su
            // jerarquia de sedes, areas y subestaciones— y no pintan nada en una
            // instalacion de DOCUFIZ. Los datos propios se traen del sistema
            // anterior, y de eso se encarga el bloque de abajo.
        ]);

        $this->traerElSistemaAnterior();

        // Los dumps legacy se insertan con IDs explícitos (SQL crudo), lo que NO
        // avanza las secuencias de Postgres → el primer create/duplicate del
        // sistema chocaría con un id ya usado. Resincronizamos al final. Solo
        // pgsql; en sqlite (tests) el comando no hace nada.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\Artisan::call('db:fix-sequences');
        }
    }

    /**
     * Los datos del sistema anterior, si la base vieja esta ahi.
     *
     * `migrate:fresh --seed` tiene que dejar la base **lista para trabajar**, y
     * en local eso incluye los 3 722 planes y las 391 personas: una base
     * sembrada pero vacia no sirve para mirar nada. Antes esto eran tres o
     * cuatro comandos sueltos que habia que acordarse de correr en orden, y
     * olvidar uno dejaba la base a medias sin decirlo.
     *
     * Si el MySQL viejo no responde —un clon nuevo, CI, un despliegue— se salta
     * y se dice por que. No es un error: es que no hay nada que traer.
     *
     * El orden importa: las plantillas de formato antes que los datos, porque
     * cada AST llenado necesita su plantilla a la que colgarse.
     */
    protected function traerElSistemaAnterior(): void
    {
        try {
            \Illuminate\Support\Facades\DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->command?->warn('Sin base del sistema anterior: se salta la migracion de datos.');
            $this->command?->line('  Para traerlos, levanta el MySQL viejo y repite. Sin ellos la base');
            $this->command?->line('  queda sembrada pero sin planes ni personas.');

            return;
        }

        \Illuminate\Support\Facades\Artisan::call('docufiz:migrate-formats', [], $this->command?->getOutput());
        \Illuminate\Support\Facades\Artisan::call('docufiz:migrate-data', ['paso' => 'todo'], $this->command?->getOutput());
    }
}
