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
 *
 * Estan los veintiun paises de Latinoamerica, no una muestra. Sembrar cuatro y
 * dejar los demas a medias es el mismo fallo con otra cara: el pais aparece en
 * el desplegable, se elige, y la ficha se queda sin ningun tipo que ofrecer. Un
 * pais sin documentos no es un pais «pendiente», es un formulario que no deja
 * dar de alta a nadie.
 *
 * Los largos y los caracteres estan comprobados uno a uno contra la fuente de
 * cada pais, no puestos de memoria, y cuando la realidad admite varias formas se
 * deja el rango ancho. La regla de mas es tan mala como la de menos: un minimo
 * de 7 en el DNI peruano dejo colar un numero de celular, y un maximo corto deja
 * fuera a gente que existe y trabaja.
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

        // ── Resto de América del Sur ─────────────────────────────────────────
        'VE' => [
            // La cédula se escribe V-12.345.678 y la del extranjero E-82.060.828,
            // pero aquí va solo el número: la letra la dice el tipo, y guardarla
            // dentro partiría en dos a la misma persona según cómo la teclee cada
            // quien. Las más antiguas tienen cinco cifras y las que se emiten hoy
            // rondan los ocho millones largos, de ahí el rango.
            ['CI',        'Cédula de Identidad',                 5,  9,  DocumentType::SOLO_CIFRAS,     false],
            ['CIE',       'Cédula de Identidad de Extranjero',   5,  9,  DocumentType::SOLO_CIFRAS,     true],
            ['PASAPORTE', 'Pasaporte',                           6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'EC' => [
            // Diez cifras exactas: dos de provincia, siete de secuencia y el
            // verificador. El extranjero residente en Ecuador recibe esta misma
            // cédula, así que no hay un documento aparte que sembrar y el que
            // llega de fuera se identifica con su pasaporte.
            ['CI',        'Cédula de Identidad',                 10, 10, DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                           6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'BO' => [
            // Va como cifras y letras por el complemento: cuando dos personas
            // comparten número, el SEGIP le añade a una un sufijo tipo «1A». Se
            // guarda pegado y sin el guion, tal como lo pide la factura
            // boliviana. Exigir solo dígitos dejaría fuera justo a quien lleva el
            // complemento, que es quien más lo necesita para no confundirse con
            // el otro.
            ['CI',        'Cédula de Identidad',                 5,  10, DocumentType::CIFRAS_Y_LETRAS, false],
            ['CIE',       'Cédula de Identidad de Extranjero',   5,  12, DocumentType::CIFRAS_Y_LETRAS, true],
            ['PASAPORTE', 'Pasaporte',                           6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'PY' => [
            // La cédula paraguaya no tiene largo fijo: las viejas llevan cuatro
            // cifras y las de ahora siete u ocho. El extranjero con admisión
            // permanente recibe una cédula igual que la del paraguayo, con lo
            // que tampoco hay documento aparte.
            ['CI',        'Cédula de Identidad Civil',           4,  9,  DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                           6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'AR' => [
            // Siete cifras en la gente mayor y ocho desde hace décadas. Al
            // extranjero residente se le da también un DNI, pero con numeración
            // propia (los 9x millones), así que son dos filas y no una: es lo
            // único que distingue al de fuera en la puerta de la obra.
            ['DNI',       'Documento Nacional de Identidad',     7,  8,  DocumentType::SOLO_CIFRAS,     false],
            ['DNIE',      'DNI para Extranjeros',                7,  9,  DocumentType::SOLO_CIFRAS,     true],
            ['PASAPORTE', 'Pasaporte',                           6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'UY' => [
            // Siete cifras y el verificador; las antiguas llegan a seis. Se
            // guarda sin el punto ni el guion con que se escribe (1.234.567-8).
            ['CI',        'Cédula de Identidad',                 6,  8,  DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                           6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],

        // ── México, Centroamérica y el Caribe ────────────────────────────────
        'MX' => [
            // Dieciocho caracteres con letras dentro (iniciales, fecha, sexo,
            // estado y homoclave), así que de solo cifras no tiene nada. El
            // residente extranjero también acaba teniendo CURP, pero lo que
            // enseña en la obra es su tarjeta de residente.
            ['CURP',      'Clave Única de Registro de Población', 18, 18, DocumentType::CIFRAS_Y_LETRAS, false],
            ['TR',        'Tarjeta de Residente',                 5,  20, DocumentType::CIFRAS_Y_LETRAS, true],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'GT' => [
            // El CUI del DPI son trece cifras: ocho de correlativo, una de
            // verificación, dos de departamento y dos de municipio.
            ['DPI',       'Documento Personal de Identificación', 13, 13, DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'SV' => [
            ['DUI',       'Documento Único de Identidad',         9,  9,  DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'HN' => [
            ['DNI',       'Documento Nacional de Identificación', 13, 13, DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'NI' => [
            // Catorce caracteres, y el último es una letra: 001-150668-0001X. Se
            // guarda sin guiones. Si se sembrara como solo cifras no entraría
            // ninguna cédula nicaragüense, ni una.
            ['CI',        'Cédula de Identidad',                  14, 14, DocumentType::CIFRAS_Y_LETRAS, false],
            ['CR',        'Cédula de Residencia',                 5,  20, DocumentType::CIFRAS_Y_LETRAS, true],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'CR' => [
            // Nueve cifras la del costarricense, once o doce la del extranjero
            // residente (el DIMEX), y ninguna de las dos lleva el cero delante ni
            // los guiones con que se imprimen.
            ['CI',        'Cédula de Identidad',                  9,  9,  DocumentType::SOLO_CIFRAS,     false],
            ['DIMEX',     'Documento de Identidad Migratorio para Extranjeros', 11, 12, DocumentType::SOLO_CIFRAS, true],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'PA' => [
            // La panameña no es un número a secas: lleva delante la provincia y
            // a veces letras —N de naturalizado, E de extranjero residente, PE y
            // PI—, como 8-123-4567 o E-8-98765. Se guarda sin guiones, y por eso
            // admite letras. La E es la del extranjero, que va en su propia fila
            // porque es lo que marca de dónde viene quien entra a la obra.
            ['CIP',       'Cédula de Identidad Personal',         5,  14, DocumentType::CIFRAS_Y_LETRAS, false],
            ['CE',        'Cédula de Extranjero',                 5,  14, DocumentType::CIFRAS_Y_LETRAS, true],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'CU' => [
            // Once cifras, y las seis primeras son la fecha de nacimiento.
            ['CI',        'Carné de Identidad',                   11, 11, DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'DO' => [
            // Once cifras. Se escribe 001-1234567-8 y se guarda sin guiones.
            ['CED',       'Cédula de Identidad y Electoral',      11, 11, DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'HT' => [
            // La CIN nueva lleva un NIN de diez cifras, pero la anterior tenía
            // diecisiete y sigue en manos de gente que aún no la ha cambiado. El
            // máximo se deja en diecisiete a propósito: cerrarlo en diez sería
            // pedirle a un haitiano que renueve su carné para poder fichar.
            ['CIN',       'Carte d’Identification Nationale',     10, 17, DocumentType::SOLO_CIFRAS,     false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
        ],
        'PR' => [
            // Puerto Rico no tiene documento nacional propio: en la obra se
            // identifica con la licencia o la tarjeta del DTOP, y el número no
            // sigue un formato publicado, así que el rango va ancho y con letras
            // en vez de inventarle una regla que rechace tarjetas de verdad.
            ['LIC',       'Licencia de Conducir o Tarjeta de Identificación (DTOP)', 5, 12, DocumentType::CIFRAS_Y_LETRAS, false],
            ['PASAPORTE', 'Pasaporte',                            6,  20, DocumentType::CIFRAS_Y_LETRAS, true],
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

        // El RIF venezolano empieza por letra —J la empresa, G el organismo
        // público— y le siguen nueve cifras.
        'VE' => [['RIF',  'Registro Único de Información Fiscal', 9, 10, DocumentType::CIFRAS_Y_LETRAS]],
        // Trece cifras: la cédula o el correlativo, más el 001 del
        // establecimiento.
        'EC' => [['RUC',  'Registro Único de Contribuyentes', 13, 13, DocumentType::SOLO_CIFRAS]],
        // El NIT boliviano no tiene largo fijo —lo arma Impuestos Nacionales a
        // partir del contribuyente— así que el rango es ancho a sabiendas.
        'BO' => [['NIT',  'Número de Identificación Tributaria', 7, 15, DocumentType::SOLO_CIFRAS]],
        // Base de tres a ocho cifras y el verificador detrás del guion
        // (80012345-6). Se guarda sin guion.
        'PY' => [['RUC',  'Registro Único del Contribuyente', 4, 9, DocumentType::SOLO_CIFRAS]],
        'AR' => [['CUIT', 'Clave Única de Identificación Tributaria', 11, 11, DocumentType::SOLO_CIFRAS]],
        // Doce cifras: oficina, empresa, dependencia y verificador.
        'UY' => [['RUT',  'Registro Único Tributario', 12, 12, DocumentType::SOLO_CIFRAS]],
        // Doce caracteres la persona moral y trece la física, y las tres
        // primeras posiciones son letras del nombre.
        'MX' => [['RFC',  'Registro Federal de Contribuyentes', 12, 13, DocumentType::CIFRAS_Y_LETRAS]],
        // El verificador del NIT guatemalteco puede ser una K, que es el 10. Si
        // se sembrara como solo cifras, uno de cada once NIT quedaría fuera.
        'GT' => [['NIT',  'Número de Identificación Tributaria', 6, 13, DocumentType::CIFRAS_Y_LETRAS]],
        'SV' => [['NIT',  'Número de Identificación Tributaria', 14, 14, DocumentType::SOLO_CIFRAS]],
        'HN' => [['RTN',  'Registro Tributario Nacional', 14, 14, DocumentType::SOLO_CIFRAS]],
        // Catorce caracteres y empieza por J en la persona jurídica.
        'NI' => [['RUC',  'Registro Único de Contribuyentes', 14, 14, DocumentType::CIFRAS_Y_LETRAS]],
        'CR' => [['CJ',   'Cédula Jurídica', 10, 10, DocumentType::SOLO_CIFRAS]],
        // El RUC panameño arrastra el tomo, folio y asiento del Registro
        // Público, con letras incluidas, y detrás el DV.
        'PA' => [['RUC',  'Registro Único de Contribuyentes', 5, 20, DocumentType::CIFRAS_Y_LETRAS]],
        // De la ONAT. No hay publicado un largo fijo para la persona jurídica,
        // así que se deja el rango abierto en lugar de fijar uno inventado.
        'CU' => [['NIT',  'Número de Identificación Tributaria', 8, 14, DocumentType::SOLO_CIFRAS]],
        'DO' => [['RNC',  'Registro Nacional de Contribuyentes', 9, 9, DocumentType::SOLO_CIFRAS]],
        // El NIF haitiano son diez cifras, la última de control; los más
        // antiguos que siguen circulando tienen nueve.
        'HT' => [['NIF',  'Numéro d’Identification Fiscale', 9, 10, DocumentType::SOLO_CIFRAS]],
        // Puerto Rico tributa con el sistema federal: el patrono se identifica
        // con el EIN, nueve cifras.
        'PR' => [['EIN',  'Número de Identificación Patronal (EIN)', 9, 9, DocumentType::SOLO_CIFRAS]],
    ];

    public function run(): void
    {
        // Los dos catálogos, no solo el de personas: un país al que solo se le
        // sembrara el documento tributario quedaría fuera del `whereIn` y sus
        // empresas no tendrían con qué darse de alta.
        $isos = array_values(array_unique(array_merge(
            array_keys(self::PERSONAS),
            array_keys(self::EMPRESAS),
        )));

        $paises = Country::whereIn('iso_code', $isos)->pluck('id', 'iso_code');

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
