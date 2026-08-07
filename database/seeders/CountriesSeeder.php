<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CountriesSeeder — catálogo geográfico (26 países reales, incl. los de los
 * clientes del sistema viejo: Centroamérica, Caribe, India).
 *
 * Idempotente: updateOrInsert por id, re-runable sin duplicar.
 * region_id y default_locale_id son NOT NULL — los IDs siguen los seeders
 * de Regions y Locales (ojo si cambian de orden).
 *
 * Region map:
 *   1 = América del Sur, 2 = América del Norte, 3 = América Central/Caribe,
 *   4 = Europa, 5 = Asia, 6 = África, 7 = Oceanía, 9 = Medio Oriente,
 *   10 = Sudeste Asiático
 *
 * Locale map (LocalesSeeder):
 *   1 = es_PE, 2 = es_VE, 3 = pt_BR, 4 = en_US, 5 = es_CL
 */
class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['id' => 1,  'region_id' => 1,  'default_locale_id' => 1, 'name' => 'Perú',           'iso_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima'],
            ['id' => 2,  'region_id' => 1,  'default_locale_id' => 2, 'name' => 'Venezuela',      'iso_code' => 'VE', 'currency' => 'VES', 'timezone' => 'America/Caracas'],
            ['id' => 3,  'region_id' => 1,  'default_locale_id' => 3, 'name' => 'Brasil',         'iso_code' => 'BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo'],
            ['id' => 4,  'region_id' => 2,  'default_locale_id' => 4, 'name' => 'Estados Unidos', 'iso_code' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York'],
            ['id' => 5,  'region_id' => 1,  'default_locale_id' => 5, 'name' => 'Chile',          'iso_code' => 'CL', 'currency' => 'CLP', 'timezone' => 'America/Santiago'],
            ['id' => 6,  'region_id' => 1,  'default_locale_id' => 1, 'name' => 'Argentina',      'iso_code' => 'AR', 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires'],
            ['id' => 7,  'region_id' => 1,  'default_locale_id' => 1, 'name' => 'Colombia',       'iso_code' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota'],
            ['id' => 8,  'region_id' => 2,  'default_locale_id' => 1, 'name' => 'México',         'iso_code' => 'MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City'],
            ['id' => 9,  'region_id' => 4,  'default_locale_id' => 1, 'name' => 'España',         'iso_code' => 'ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid'],
            ['id' => 10, 'region_id' => 4,  'default_locale_id' => 4, 'name' => 'Reino Unido',    'iso_code' => 'GB', 'currency' => 'GBP', 'timezone' => 'Europe/London'],
            ['id' => 11, 'region_id' => 4,  'default_locale_id' => 4, 'name' => 'Alemania',       'iso_code' => 'DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin'],
            ['id' => 12, 'region_id' => 4,  'default_locale_id' => 4, 'name' => 'Francia',        'iso_code' => 'FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris'],
            ['id' => 13, 'region_id' => 4,  'default_locale_id' => 4, 'name' => 'Italia',         'iso_code' => 'IT', 'currency' => 'EUR', 'timezone' => 'Europe/Rome'],
            ['id' => 14, 'region_id' => 5,  'default_locale_id' => 4, 'name' => 'Japón',          'iso_code' => 'JP', 'currency' => 'JPY', 'timezone' => 'Asia/Tokyo'],
            ['id' => 15, 'region_id' => 5,  'default_locale_id' => 4, 'name' => 'China',          'iso_code' => 'CN', 'currency' => 'CNY', 'timezone' => 'Asia/Shanghai'],
            // América Central / Caribe + otros (países de los clientes reales del sistema viejo).
            ['id' => 16, 'region_id' => 3,  'default_locale_id' => 1, 'name' => 'Panamá',               'iso_code' => 'PA', 'currency' => 'USD', 'timezone' => 'America/Panama'],
            ['id' => 17, 'region_id' => 3,  'default_locale_id' => 1, 'name' => 'República Dominicana',  'iso_code' => 'DO', 'currency' => 'DOP', 'timezone' => 'America/Santo_Domingo'],
            ['id' => 18, 'region_id' => 3,  'default_locale_id' => 1, 'name' => 'Nicaragua',             'iso_code' => 'NI', 'currency' => 'NIO', 'timezone' => 'America/Managua'],
            ['id' => 19, 'region_id' => 3,  'default_locale_id' => 4, 'name' => 'Jamaica',               'iso_code' => 'JM', 'currency' => 'JMD', 'timezone' => 'America/Jamaica'],
            ['id' => 20, 'region_id' => 3,  'default_locale_id' => 4, 'name' => 'Barbados',              'iso_code' => 'BB', 'currency' => 'BBD', 'timezone' => 'America/Barbados'],
            ['id' => 21, 'region_id' => 3,  'default_locale_id' => 4, 'name' => 'Trinidad y Tobago',     'iso_code' => 'TT', 'currency' => 'TTD', 'timezone' => 'America/Port_of_Spain'],
            ['id' => 22, 'region_id' => 1,  'default_locale_id' => 1, 'name' => 'Uruguay',               'iso_code' => 'UY', 'currency' => 'UYU', 'timezone' => 'America/Montevideo'],
            ['id' => 23, 'region_id' => 3,  'default_locale_id' => 1, 'name' => 'El Salvador',           'iso_code' => 'SV', 'currency' => 'USD', 'timezone' => 'America/El_Salvador'],
            ['id' => 24, 'region_id' => 3,  'default_locale_id' => 1, 'name' => 'Honduras',              'iso_code' => 'HN', 'currency' => 'HNL', 'timezone' => 'America/Tegucigalpa'],
            ['id' => 25, 'region_id' => 3,  'default_locale_id' => 1, 'name' => 'Costa Rica',            'iso_code' => 'CR', 'currency' => 'CRC', 'timezone' => 'America/Costa_Rica'],
            ['id' => 26, 'region_id' => 5,  'default_locale_id' => 4, 'name' => 'India',                 'iso_code' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata'],
        ];

        foreach ($countries as $c) {
            DB::table('countries')->updateOrInsert(
                ['id' => $c['id']],
                [
                    'slug'              => Str::random(22),
                    'region_id'         => $c['region_id'],
                    'default_locale_id' => $c['default_locale_id'],
                    'name'              => $c['name'],
                    'iso_code'          => $c['iso_code'],
                    'currency'          => $c['currency'],
                    'timezone'          => $c['timezone'],
                    'is_active'         => true,
                    'created_by'        => 1,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('countries_id_seq', COALESCE((SELECT MAX(id) FROM countries), 0) + 1, false)");
        }

        $this->command?->info('Countries seeded: ' . count($countries) . ' real countries.');
    }
}
