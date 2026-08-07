<?php

namespace Database\Seeders;

use App\Models\TransformerPreservation;
use Illuminate\Database\Seeder;

/**
 * TransformerPreservationsSeeder — catálogo inicial de sistemas de preservación
 * del aceite. Valores migrados del sistema viejo. Idempotente (firstOrCreate por
 * code). El "-" es el escape (no especificado).
 */
class TransformerPreservationsSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['code' => 'conservator_membrane', 'name' => 'Conservador con membrana'],
            ['code' => 'sealed_nitrogen',      'name' => 'Tanque sellado con nitrógeno'],
            ['code' => 'free_breathing',       'name' => 'Conservador con respiración libre'],
            ['code' => 'na',                   'name' => '-'],
        ];

        foreach ($items as $i => $it) {
            TransformerPreservation::firstOrCreate(
                ['code' => $it['code']],
                ['name' => $it['name'], 'sort_order' => $i + 1, 'is_active' => true, 'created_by' => 1],
            );
        }
    }
}
