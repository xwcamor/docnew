<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LanguagesSeeder — catálogo maestro de idiomas (14 lenguas reales).
 *
 * Idempotente: usa updateOrInsert por id, se puede re-correr sin duplicar.
 * Códigos ISO 639-1 estándar. Los `id` están forzados porque otros seeders
 * (LocalesSeeder) los referencian por FK fija.
 */
class LanguagesSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['id' => 1,  'iso_code' => 'es', 'name' => 'Español'],
            ['id' => 2,  'iso_code' => 'en', 'name' => 'English'],
            ['id' => 3,  'iso_code' => 'pt', 'name' => 'Português'],
            ['id' => 4,  'iso_code' => 'fr', 'name' => 'Français'],
            ['id' => 5,  'iso_code' => 'de', 'name' => 'Deutsch'],
            ['id' => 6,  'iso_code' => 'it', 'name' => 'Italiano'],
            ['id' => 7,  'iso_code' => 'zh', 'name' => '中文'],
            ['id' => 8,  'iso_code' => 'ja', 'name' => '日本語'],
            ['id' => 9,  'iso_code' => 'ko', 'name' => '한국어'],
            ['id' => 10, 'iso_code' => 'ar', 'name' => 'العربية'],
            ['id' => 11, 'iso_code' => 'ru', 'name' => 'Русский'],
            ['id' => 12, 'iso_code' => 'hi', 'name' => 'हिन्दी'],
            ['id' => 13, 'iso_code' => 'nl', 'name' => 'Nederlands'],
            ['id' => 14, 'iso_code' => 'tr', 'name' => 'Türkçe'],
        ];

        foreach ($languages as $l) {
            DB::table('languages')->updateOrInsert(
                ['id' => $l['id']],
                [
                    'slug'       => Str::random(22),
                    'iso_code'   => $l['iso_code'],
                    'name'       => $l['name'],
                    // Solo es/en tienen traducciones reales → activos. El resto
                    // queda inactivo (no aparece en el switcher) hasta traducirlo
                    // y agregarlo a supportedLocales. Evita keys crudas.
                    'is_active'  => in_array($l['iso_code'], ['es', 'en'], true),
                    'created_by' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('languages_id_seq', COALESCE((SELECT MAX(id) FROM languages), 0) + 1, false)");
        }

        $this->command?->info('Languages seeded: ' . count($languages) . ' real languages (ISO 639-1).');
    }
}
