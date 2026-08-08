<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Los tipos de documento de Peru, que hasta ahora vivian dentro del codigo.
 *
 * `StorePersonRequest` traia `Rule::in(['DNI', 'CE', 'PASAPORTE'])`, asi que
 * el PTP —el permiso temporal de permanencia, que en Peru llevan miles de
 * venezolanos— no se podia usar sin tocar PHP. Aqui son filas.
 *
 * Las longitudes son **ayuda al dar de alta**, no la condicion para buscar. El
 * volcado de la v1 tiene dos peruanos con DNI de 7 caracteres, asi que el
 * minimo del DNI se deja en 7 y no en 8: una regla mas estricta que la realidad
 * deja gente fuera del sistema, y esa gente existe.
 *
 * Solo se siembra Peru porque es donde se opera (el 100 % de los 3 722 planes).
 * Otro pais es otra fila, y ahora se hace desde la pantalla.
 */
class DocumentTypesSeeder extends Seeder
{
    public function run(): void
    {
        $peru = Country::where('iso_code', 'PE')->first();

        if (! $peru) {
            $this->command?->warn('DocumentTypesSeeder: no hay Perú, no se siembra nada.');

            return;
        }

        $tipos = [
            // El DNI tiene 8 dígitos desde hace años, pero en la base hay dos
            // de 7 que son reales y siguen trabajando.
            ['code' => 'DNI',       'name' => 'Documento Nacional de Identidad', 'min' => 7,  'max' => 8],
            ['code' => 'CE',        'name' => 'Carné de Extranjería',            'min' => 9,  'max' => 12],
            ['code' => 'PTP',       'name' => 'Permiso Temporal de Permanencia', 'min' => 9,  'max' => 12],
            ['code' => 'PASAPORTE', 'name' => 'Pasaporte',                       'min' => 6,  'max' => 20],
        ];

        foreach ($tipos as $t) {
            DocumentType::firstOrCreate(
                ['country_id' => $peru->id, 'code' => $t['code']],
                [
                    'slug'       => Str::random(22),
                    'name'       => $t['name'],
                    'min_length' => $t['min'],
                    'max_length' => $t['max'],
                    'is_active'  => true,
                    'created_by' => 1,
                ],
            );
        }

        $this->command?->info('Tipos de documento sembrados para Perú: ' . count($tipos) . '.');
    }
}
