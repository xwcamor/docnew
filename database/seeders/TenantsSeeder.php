<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TenantsSeeder — workspaces de prueba para desarrollo.
 *
 * Cada tenant es una "empresa cliente" del SaaS:
 *   - Empresa 1     (id=1) → admin: joe@example.com
 *   - Empresa 2     (id=2) → admin: yugi@example.com
 *   - Independiente (id=3) → admin: independiente@example.com
 *
 * Cada tenant tiene un system_user invisible asociado al final del seed
 * (creado por TenantSystemUserService). Ese usuario es el dueno de los
 * tokens API de Sanctum: sin el no hay integracion externa posible.
 *
 * Idempotente: usa updateOrInsert por id (el slug se preserva si ya existia).
 */
class TenantsSeeder extends Seeder
{
    public function run(): void
    {
        // Disclaimer legal por defecto de los informes PDF (membrete del tenant).
        // Texto genérico de laboratorio; cada empresa lo ajusta a su gusto.
        $disclaimer = fn (string $empresa) => "Los resultados de este informe corresponden únicamente a las "
            . "muestras analizadas bajo las condiciones de ensayo. {$empresa} no se responsabiliza por componentes "
            . "proporcionados por el cliente ni por el uso inadecuado de este documento. No se otorga garantía "
            . "expresa o implícita sobre la condición, productividad o correcto funcionamiento del equipo. Se prohíbe "
            . "la reproducción total o parcial de este documento sin autorización previa escrita. Los análisis y "
            . "opiniones representan el mejor juicio de {$empresa} y no son refrendados por un ente acreditador.";

        $tenants = [
            ['id' => 1, 'name' => 'Empresa 1',     'address' => 'Av. Industrial 1234, Urb. Las Praderas, Lima — Perú'],
            ['id' => 2, 'name' => 'Empresa 2',     'address' => 'Calle Los Transformadores 567, Cerro Colorado, Arequipa — Perú'],
            ['id' => 3, 'name' => 'Independiente',  'address' => 'Jr. Eléctrico 89, Wanchaq, Cusco — Perú'],
        ];

        // Timezone explícito en cada workspace seed. Sin esto, el booted()
        // del modelo intentaria derivarlo del country del creator — pero
        // como insertamos via DB::table() (raw, sin eventos del modelo) el
        // hook no corre. Mejor ser explícitos.
        foreach ($tenants as $t) {
            $existingSlug = DB::table('tenants')->where('id', $t['id'])->value('slug');
            DB::table('tenants')->updateOrInsert(
                ['id' => $t['id']],
                [
                    'slug'              => $existingSlug ?? Str::random(22),
                    'name'              => $t['name'],
                    'address'           => $t['address'],
                    'report_disclaimer' => $disclaimer($t['name']),
                    'is_active'         => true,
                    'timezone'          => 'America/Lima',
                    'created_by'        => 1,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]
            );
        }

        // Reset auto-increment para que el proximo INSERT continue despues del ultimo id.
        if (config('database.default') === 'pgsql') {
            DB::statement("SELECT setval('tenants_id_seq', COALESCE((SELECT MAX(id) FROM tenants), 0) + 1, false)");
        }

        $this->command?->info('Tenants seeded: Empresa 1 (id=1), Empresa 2 (id=2), Independiente (id=3).');

        // System users — invisibles, duenos de los tokens API. Idempotente.
        $service = app(\App\Services\SystemManagement\TenantSystemUserService::class);
        foreach (\App\Models\Tenant::all() as $tenant) {
            $service->ensureFor($tenant);
        }
        $this->command?->info('System users created/linked for all tenants.');
    }
}
