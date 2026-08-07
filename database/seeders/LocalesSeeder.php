<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LocalesSeeder — catálogo maestro de dialectos regionales (12 locales reales).
 *
 * Idempotente: updateOrInsert por id, re-runable sin duplicar.
 * language_id es NOT NULL — los IDs siguen los que define LanguagesSeeder.
 *
 * Language map (LanguagesSeeder):
 *   1 = es, 2 = en, 3 = pt, 4 = fr, 5 = de, 6 = it,
 *   7 = zh, 8 = ja, 9 = ko, 10 = ar, 11 = ru, 12 = hi, 13 = nl, 14 = tr
 */
class LocalesSeeder extends Seeder
{
    public function run(): void
    {
        $locales = [
            ['id' => 1,  'language_id' => 1,  'code' => 'es_PE', 'name' => 'Español (Perú)'],
            ['id' => 2,  'language_id' => 1,  'code' => 'es_VE', 'name' => 'Español (Venezuela)'],
            ['id' => 3,  'language_id' => 3,  'code' => 'pt_BR', 'name' => 'Português (Brasil)'],
            ['id' => 4,  'language_id' => 2,  'code' => 'en_US', 'name' => 'English (US)'],
            ['id' => 5,  'language_id' => 1,  'code' => 'es_CL', 'name' => 'Español (Chile)'],
            ['id' => 6,  'language_id' => 1,  'code' => 'es_AR', 'name' => 'Español (Argentina)'],
            ['id' => 7,  'language_id' => 1,  'code' => 'es_MX', 'name' => 'Español (México)'],
            ['id' => 8,  'language_id' => 1,  'code' => 'es_ES', 'name' => 'Español (España)'],
            ['id' => 9,  'language_id' => 2,  'code' => 'en_GB', 'name' => 'English (UK)'],
            ['id' => 10, 'language_id' => 4,  'code' => 'fr_FR', 'name' => 'Français (France)'],
            ['id' => 11, 'language_id' => 5,  'code' => 'de_DE', 'name' => 'Deutsch (Deutschland)'],
            ['id' => 12, 'language_id' => 6,  'code' => 'it_IT', 'name' => 'Italiano (Italia)'],
        ];

        foreach ($locales as $l) {
            DB::table('locales')->updateOrInsert(
                ['id' => $l['id']],
                [
                    'slug'        => Str::random(22),
                    'code'        => $l['code'],
                    'name'        => $l['name'],
                    'language_id' => $l['language_id'],
                    'is_active'   => true,
                    'created_by'  => 1,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('locales_id_seq', COALESCE((SELECT MAX(id) FROM locales), 0) + 1, false)");
        }

        $this->command?->info('Locales seeded: ' . count($locales) . ' real locales (BCP-47).');
    }
}
