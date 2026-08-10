<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Que documento lleva una persona y que documento lleva una empresa, por pais.
 *
 * `StorePersonRequest` traia `Rule::in(['DNI', 'CE', 'PASAPORTE'])`, asi que el
 * PTP —el permiso temporal de permanencia, que en Peru llevan miles de
 * venezolanos— no se podia usar sin tocar PHP. Aqui son filas.
 *
 * Y son filas POR PAIS, que es lo que hace que el formulario ofrezca lo que
 * corresponde: eliges Chile y sale el RUT, no el RUC. Antes, un pais sin tipos
 * se caia a la lista de Peru y elegir India te ofrecia un DNI.
 *
 * Las longitudes se aplican **al dar de alta**, no al buscar: la cuadrilla se
 * busca por coincidencia exacta del numero, asi que una persona ya migrada con
 * un documento raro se sigue encontrando aunque hoy no se pudiera dar de alta.
 *
 * `for_foreigners` marca el documento que lleva quien NO es del pais. De ahi
 * sale `Person::is_foreigner`, que es lo que se comprueba en la puerta — antes
 * habia una columna `nationality_id` preguntando lo mismo, y se borro.
 *
 * Peru es donde se opera (el 100 % de los 3 722 planes). Los demas van sembrados
 * para que el dia que entre un cliente de alli el selector no salga vacio; sus
 * catalogos son cortos a proposito, con lo que se usa de verdad para identificar
 * a alguien en una obra. Cualquier pais mas es otra fila, y eso ya se hace desde
 * la pantalla.
 */
class DocumentTypesSeeder extends Seeder
{
    /**
     * [sigla, nombre, minimo, maximo, caracteres, lo lleva un extranjero]
     *
     * El pasaporte se marca como de extranjero en todos: quien trabaja en su
     * propio pais se identifica con su documento nacional, asi que el que llega
     * con pasaporte viene de fuera.
     */
    private const PERSONAS = [
        'PE' => [
            // 8 exactos. Se dejo en 7 por dos peruanos del volcado que tenian
            // el DNI corto, pero al repasar la base maestra no quedaba ninguno:
            // el unico documento peruano que no era de 8 digitos resulto ser un
            // numero de celular tecleado en el campo del documento. Un minimo
            // mas flojo que la realidad es justo lo que deja entrar esa basura.
            ['DNI',       'Documento Nacional de Identidad', 8,  8,  DocumentType::SOLO_CIFRAS,     false],
            ['CE',        'Carné de Extranjería',            9,  12, DocumentType::SOLO_CIFRAS,     true],
            ['PTP',       'Permiso Temporal de Permanencia', 9,  12, DocumentType::SOLO_CIFRAS,     true],
            ['PASAPORTE', 'Pasaporte',                       6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'CL' => [
            // El RUN lleva digito verificador, que puede ser una K.
            ['RUN',       'Rol Único Nacional',              8,  10, DocumentType::CIFRAS_Y_LETRAS, false],
            ['CI',        'Cédula de Identidad para Extranjeros', 6, 12, DocumentType::CIFRAS_Y_LETRAS, true],
            ['PASAPORTE', 'Pasaporte',                       6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'CO' => [
            ['CC',        'Cédula de Ciudadanía',            6,  10, DocumentType::SOLO_CIFRAS,     false],
            ['CE',        'Cédula de Extranjería',           6,  10, DocumentType::SOLO_CIFRAS,     true],
            ['PPT',       'Permiso por Protección Temporal', 6,  12, DocumentType::SOLO_CIFRAS,     true],
            ['PASAPORTE', 'Pasaporte',                       6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'BR' => [
            ['CPF',       'Cadastro de Pessoas Físicas',     11, 11, DocumentType::SOLO_CIFRAS,     false],
            ['RNE',       'Registro Nacional de Estrangeiro', 6, 12, DocumentType::CIFRAS_Y_LETRAS, true],
            ['PASAPORTE', 'Pasaporte',                       6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
    ];

    /** [sigla, nombre, minimo, maximo, caracteres] */
    private const EMPRESAS = [
        // Once digitos exactos y empieza por 10 o 20, pero la regla de los dos
        // primeros no se mete aqui: hay RUC de otras formas en circulacion y una
        // regla mas dura que la realidad deja fuera a empresas de verdad.
        'PE' => [['RUC',  'Registro Único de Contribuyentes', 11, 11, DocumentType::SOLO_CIFRAS]],
        'CL' => [['RUT',  'Rol Único Tributario',              8, 10, DocumentType::CIFRAS_Y_LETRAS]],
        'CO' => [['NIT',  'Número de Identificación Tributaria', 9, 10, DocumentType::SOLO_CIFRAS]],
        'BR' => [['CNPJ', 'Cadastro Nacional da Pessoa Jurídica', 14, 14, DocumentType::SOLO_CIFRAS]],
    ];

    public function run(): void
    {
        $paises = Country::whereIn('iso_code', array_keys(self::PERSONAS))->pluck('id', 'iso_code');

        if ($paises->isEmpty()) {
            $this->command?->warn('DocumentTypesSeeder: no hay ningún país sembrado, no se siembra nada.');

            return;
        }

        $sembrados = 0;
        $completados = 0;

        foreach ([DocumentType::PERSONA => self::PERSONAS, DocumentType::EMPRESA => self::EMPRESAS] as $scope => $porPais) {
            foreach ($porPais as $iso => $tipos) {
                $paisId = $paises[$iso] ?? null;

                if (! $paisId) {
                    continue;
                }

                foreach ($tipos as $t) {
                    [$code, $name, $min, $max, $chars] = $t;

                    $fila = DocumentType::withoutGlobalScopes()->firstOrCreate(
                        ['country_id' => $paisId, 'scope' => $scope, 'code' => $code],
                        [
                            'slug'           => Str::random(22),
                            'name'           => $name,
                            'min_length'     => $min,
                            'max_length'     => $max,
                            'allowed_chars'  => $chars,
                            'for_foreigners' => $t[5] ?? false,
                            'is_active'      => true,
                            'created_by'     => 1,
                        ],
                    );

                    // Si la fila ya estaba, se rellena solo lo que le falte. El
                    // catálogo se edita desde la pantalla, así que sobrescribir
                    // borraría el trabajo de quien lo ajustó; pero una regla en
                    // blanco no es una decisión de nadie: es un tipo dado de alta
                    // a mano al que no le pusieron largo, y sin largo el número
                    // no se valida contra nada.
                    $faltantes = collect([
                        'min_length'    => $min,
                        'max_length'    => $max,
                        'allowed_chars' => $chars,
                    ])->filter(fn ($v, $col) => $fila->{$col} === null);

                    if ($faltantes->isNotEmpty()) {
                        $fila->forceFill($faltantes->all())->save();
                        $completados++;
                    }

                    $sembrados++;
                }
            }
        }

        $this->command?->info(
            "Tipos de documento sembrados: {$sembrados} en {$paises->count()} países ("
            . $paises->keys()->implode(', ') . ')'
            . ($completados ? ", {$completados} a los que les faltaba la regla del número." : '.')
        );
    }
}
