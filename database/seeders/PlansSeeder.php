<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PlansSeeder — los 4 tiers: free / basic / pro / enterprise.
 *
 * Idempotente vía updateOrInsert por `slug`. Re-runable sin duplicar.
 *
 * Las features bool están en JSON; los límites numéricos como columnas
 * dedicadas. -1 = ilimitado (PHP_INT_MAX en runtime).
 *
 * Diferenciadores entre tiers (ver docs/plan-features.md — SSOT):
 *   - free   → demo usable pero con tope bajo (50 registros/módulo)
 *   - basic  → profesional solo: CRUD completo + exports + saved views + audit
 *   - pro    → equipo: + team_management (Users/Roles) + bulk + import + automations
 *   - ent.   → empresa: + API + todo ilimitado + soporte prioritario
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free',
                'name' => 'Free',
                'tagline' => 'Para probar el sistema',
                'icon' => null,
                'color' => 'default',
                'sort_order' => 1,
                'max_users' => 1,
                'max_records_per_module' => 50,
                'export_rate_limit' => 1,
                'support_level' => 'community',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'features' => json_encode([
                    'export_csv'              => true,
                    'export_excel'            => false,
                    'export_pdf'              => false,
                    'export_word'             => false,
                    'audit_log_view'          => false,
                    'saved_views'             => false,
                    'team_management'         => false,
                    'report_sharing'          => false,
                    'bulk_operations'         => false,
                    'imports'                 => false,
                    'edit_all'                => false,
                    'automations'             => false,
                    'api_access'              => false,
                    'branded_exports'         => false,
                    'scheduled_exports'       => false,
                    'export_webhook_delivery' => false,
                    'export_email_delivery'   => false,
                    'extended_retention'      => false,
                    'higher_export_rate_limit'=> false,
                ]),
            ],
            [
                'slug' => 'basic',
                'name' => 'Basic',
                'tagline' => 'Profesional solo, sin equipo',
                'icon' => 'RocketOutlined',
                'color' => 'green',
                'sort_order' => 2,
                'max_users' => 1,
                'max_records_per_module' => 5_000,
                'export_rate_limit' => 3,
                'support_level' => 'email',
                'price_monthly' => 9.99,
                'price_yearly' => 99,
                'features' => json_encode([
                    'export_csv'              => true,
                    'export_excel'            => true,
                    'export_pdf'              => true,
                    'export_word'             => true,
                    'audit_log_view'          => true,
                    'saved_views'             => true,
                    'team_management'         => false,
                    'report_sharing'          => false,
                    'bulk_operations'         => false,
                    'imports'                 => false,
                    'edit_all'                => false,
                    'automations'             => false,
                    'api_access'              => false,
                    'branded_exports'         => false,
                    'scheduled_exports'       => false,
                    'export_webhook_delivery' => false,
                    'export_email_delivery'   => false,
                    'extended_retention'      => false,
                    'higher_export_rate_limit'=> false,
                ]),
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'tagline' => 'Equipo de trabajo, sin restricciones',
                'icon' => 'StarOutlined',
                'color' => 'blue',
                'sort_order' => 3,
                'max_users' => 10,
                'max_records_per_module' => 50_000,
                'export_rate_limit' => 10,
                'support_level' => 'email',
                'price_monthly' => 49,
                'price_yearly' => 499,
                'features' => json_encode([
                    'export_csv'              => true,
                    'export_excel'            => true,
                    'export_pdf'              => true,
                    'export_word'             => true,
                    'audit_log_view'          => true,
                    'saved_views'             => true,
                    'team_management'         => true,
                    'report_sharing'          => true,
                    'bulk_operations'         => true,
                    'imports'                 => true,
                    'edit_all'                => true,
                    'automations'             => true,
                    'api_access'              => false,
                    'branded_exports'         => false,
                    'scheduled_exports'       => false,
                    'export_webhook_delivery' => false,
                    'export_email_delivery'   => false,
                    'extended_retention'      => false,
                    'higher_export_rate_limit'=> false,
                ]),
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'tagline' => 'Empresa con todas las features',
                'icon' => 'CrownOutlined',
                'color' => 'gold',
                'sort_order' => 4,
                'max_users' => -1,  // ilimitado
                'max_records_per_module' => -1,
                'export_rate_limit' => 50,
                'support_level' => 'priority',
                'price_monthly' => 199,
                'price_yearly' => 1990,
                'features' => json_encode([
                    'export_csv'              => true,
                    'export_excel'            => true,
                    'export_pdf'              => true,
                    'export_word'             => true,
                    'audit_log_view'          => true,
                    'saved_views'             => true,
                    'team_management'         => true,
                    'report_sharing'          => true,
                    'bulk_operations'         => true,
                    'imports'                 => true,
                    'edit_all'                => true,
                    'automations'             => true,
                    'api_access'              => true,
                    'branded_exports'         => true,
                    'scheduled_exports'       => true,
                    'export_webhook_delivery' => true,
                    'export_email_delivery'   => true,
                    'extended_retention'      => true,
                    'higher_export_rate_limit'=> true,
                ]),
            ],
        ];

        foreach ($plans as $p) {
            DB::table('plans')->updateOrInsert(
                ['slug' => $p['slug']],
                array_merge($p, [
                    'currency'   => 'USD',
                    'is_active'  => true,
                    'is_public'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('plans_id_seq', COALESCE((SELECT MAX(id) FROM plans), 0) + 1, false)");
        }

        $this->command?->info('Plans seeded: 4 tiers (free / basic / pro / enterprise).');
    }
}
