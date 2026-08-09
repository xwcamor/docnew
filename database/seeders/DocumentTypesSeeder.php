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
 * Las longitudes se aplican **al dar de alta**, no al buscar: la cuadrilla se
 * busca por coincidencia exacta del numero, asi que una persona ya migrada con
 * un documento raro se sigue encontrando aunque hoy no se pudiera dar de alta.
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
            // 8 exactos. Se dejo en 7 por dos peruanos del volcado que tenian
            // el DNI corto, pero al repasar la base maestra no quedaba ninguno:
            // el unico documento peruano que no era de 8 digitos resulto ser un
            // numero de celular tecleado en el campo del documento. Un minimo
            // mas flojo que la realidad es justo lo que deja entrar esa basura.
            ['code' => 'DNI',       'name' => 'Documento Nacional de Identidad', 'min' => 8,  'max' => 8,  'chars' => DocumentType::SOLO_CIFRAS],
            ['code' => 'CE',        'name' => 'Carné de Extranjería',            'min' => 9,  'max' => 12, 'chars' => DocumentType::SOLO_CIFRAS],
            ['code' => 'PTP',       'name' => 'Permiso Temporal de Permanencia', 'min' => 9,  'max' => 12, 'chars' => DocumentType::SOLO_CIFRAS],
            // El unico que lleva letras.
            ['code' => 'PASAPORTE', 'name' => 'Pasaporte',                       'min' => 6,  'max' => 20, 'chars' => DocumentType::CIFRAS_Y_LETRAS],
        ];

        foreach ($tipos as $t) {
            DocumentType::firstOrCreate(
                ['country_id' => $peru->id, 'code' => $t['code']],
                [
                    'slug'       => Str::random(22),
                    'name'       => $t['name'],
                    'min_length'    => $t['min'],
                    'max_length'    => $t['max'],
                    'allowed_chars' => $t['chars'],
                    'is_active'  => true,
                    'created_by' => 1,
                ],
            );
        }

        $this->command?->info('Tipos de documento sembrados para Perú: ' . count($tipos) . '.');
    }
}
