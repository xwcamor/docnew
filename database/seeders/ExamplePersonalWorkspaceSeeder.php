<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SystemManagement\TenantSystemUserService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\Role;

/**
 * ExamplePersonalWorkspaceSeeder — caso degenerado de admin empresarial:
 * un workspace con UN solo user que es admin de sí mismo ("admin personal").
 *
 * Sirve para probar que la arquitectura no requiere código aparte para freelancers
 * vs empresas — es el mismo rol `admin`, simplemente con menos workers.
 *
 * Crea:
 *   - Tenant "Estudio Pérez" (plan free — sin suscripción)
 *   - User juanperez@example.com / 123456 con rol admin
 *
 * Idempotent: si ya existe, actualiza.
 */
class ExamplePersonalWorkspaceSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Tenant. Sin suscripción → plan 'free' derivado (no existe la
        //    columna tenants.plan). TZ Lima por default.
        $tenant = Tenant::updateOrCreate(
            ['name' => 'Estudio Pérez'],
            [
                'slug'       => Str::random(22),
                'is_active'  => true,
                'timezone'   => 'America/Lima',
                'created_by' => 1, // super
            ]
        );

        $this->command?->info("Tenant: 'Estudio Pérez' (id={$tenant->id})");

        // 2) User admin personal — TZ Lima por default (coherente con UsersSeeder).
        $user = User::withoutGlobalScopes()->updateOrCreate(
            ['email' => 'juanperez@example.com'],
            [
                'name'       => 'Juan Pérez',
                'password'   => Hash::make('123456'),
                'slug'       => User::withoutGlobalScopes()->where('email', 'juanperez@example.com')->value('slug') ?? Str::random(22),
                'tenant_id'  => $tenant->id,
                'country_id' => 1,
                'locale_id'  => 1,
                'timezone'   => 'America/Lima',
                'is_active'  => true,
                'created_by' => 1,
            ]
        );

        // 3) Asignar rol admin (el mismo que joe y maria — no hay "admin_personal" aparte)
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $user->syncRoles([$adminRole]);
            $this->command?->info("User: juanperez@example.com → admin (tenant #{$tenant->id})");
        } else {
            $this->command?->warn("Rol 'admin' no existe. Correr RolesAndPermissionsSeeder primero.");
        }

        // 4) Sequence sync (Postgres)
        if (config('database.default') === 'pgsql') {
            DB::statement("SELECT setval('tenants_id_seq', COALESCE((SELECT MAX(id) FROM tenants), 0) + 1, false)");
            DB::statement("SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 0) + 1, false)");
        }

        // 5) System user para API tokens (idempotent, no hace nada si ya existe)
        app(TenantSystemUserService::class)->ensureFor($tenant);

        $this->command?->info("Listo. Inicia sesión con juanperez@example.com / 123456 para probar 'admin personal'.");
    }
}
