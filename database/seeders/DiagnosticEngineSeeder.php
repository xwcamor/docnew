<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder maestro del motor de diagnóstico.
 * Corre los catálogos primero, luego las reglas (que dependen de los catálogos).
 *
 * Uso:
 *   php artisan db:seed --class=DiagnosticEngineSeeder
 */
class DiagnosticEngineSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DiagnosticCatalogSeeder::class,
            CromasRulesSeeder::class,
        ]);
    }
}
