<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\Position;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * El tipo de documento es del pais, no de la casa.
 *
 * El catalogo tiene `country_id` desde que existe, pero la pantalla y la
 * validacion se caian a «DNI, CE, PASAPORTE» cuando el pais elegido no tenia
 * ninguno sembrado — y esos tres son los PERUANOS. El resultado, tal cual lo
 * conto quien lo vio: eliges India y te ofrece un DNI. Peor todavia, la
 * validacion tambien se caia a la misma lista, asi que el alta pasaba y quedaba
 * un indio con documento peruano.
 *
 * Esa red tenia sentido cuando no habia catalogo: una base recien levantada
 * dejaba la pantalla sin poder dar de alta a nadie. Lo que estaba mal era usarla
 * para las dos cosas. Aqui se separan: sin catalogo EN NINGUNA PARTE se deja
 * pasar; con catalogo pero ninguno de ese pais, se dice y no se inventa.
 */
class TipoDeDocumentoPorPaisTest extends CatalogTestCase
{
    /** India: existe en `countries` y no tiene ni un tipo sembrado. */
    private const INDIA = 77;

    /**
     * Latinoamerica entera —las Americas de habla española, portuguesa y
     * francesa— con el documento de casa y el de la empresa de cada pais.
     *
     * Esta escrita aqui y no leida del seeder a proposito: si la prueba
     * preguntara por lo mismo que siembra, borrar un pais del seeder tambien lo
     * borraria de la prueba y no fallaria nada. La lista es la exigencia, y el
     * seeder tiene que cumplirla.
     *
     * Faltaban siete —Ecuador, Bolivia, Paraguay, Guatemala, Cuba, Haiti y
     * Puerto Rico— y de los que estaban, diecisiete no tenian ni un tipo de
     * documento. Un pais en el desplegable sin tipos que ofrecer es una ficha
     * que no se puede guardar, que es el fallo que se estaba arreglando.
     *
     * Jamaica, Barbados y Trinidad no salen: son Caribe anglofono, no
     * Latinoamerica. Estan en `countries` porque llegaron con clientes reales
     * del sistema anterior, y ahi se quedan.
     */
    private const LATINOAMERICA = [
        'PE' => ['DNI',  'RUC'],
        'VE' => ['CI',   'RIF'],
        'EC' => ['CI',   'RUC'],
        'BO' => ['CI',   'NIT'],
        'PY' => ['CI',   'RUC'],
        'BR' => ['CPF',  'CNPJ'],
        'CL' => ['RUN',  'RUT'],
        'AR' => ['DNI',  'CUIT'],
        'UY' => ['CI',   'RUT'],
        'CO' => ['CC',   'NIT'],
        'MX' => ['CURP', 'RFC'],
        'GT' => ['DPI',  'NIT'],
        'SV' => ['DUI',  'NIT'],
        'HN' => ['DNI',  'RTN'],
        'NI' => ['CI',   'RUC'],
        'CR' => ['CI',   'CJ'],
        'PA' => ['CIP',  'RUC'],
        'CU' => ['CI',   'NIT'],
        'DO' => ['CED',  'RNC'],
        'HT' => ['CIN',  'NIF'],
        'PR' => ['LIC',  'EIN'],
    ];

    /**
     * Los paises que ya estaban, con el id con el que estaban.
     *
     * Ampliar el catalogo es añadir filas al final, nunca reordenar: de estos
     * ids cuelgan 3 722 planes de trabajo, unas 4 000 personas y los tipos de
     * documento ya sembrados. Renumerar aqui es cambiarle el pais a datos reales
     * sin que nadie los toque, y no se veria hasta que alguien abriera una ficha
     * vieja.
     */
    private const IDS_QUE_NO_SE_MUEVEN = [
        1 => 'PE', 2 => 'VE', 3 => 'BR', 4 => 'US', 5 => 'CL', 6 => 'AR', 7 => 'CO', 8 => 'MX',
        16 => 'PA', 17 => 'DO', 18 => 'NI', 19 => 'JM', 20 => 'BB', 21 => 'TT',
        22 => 'UY', 23 => 'SV', 24 => 'HN', 25 => 'CR', 26 => 'IN',
    ];

    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('countries')->insertOrIgnore([[
            'id' => self::INDIA, 'slug' => Str::random(22), 'region_id' => 999,
            'name' => 'India', 'iso_code' => 'IN', 'currency' => 'INR', 'timezone' => 'UTC',
            'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $this->tipo(1, 'DNI', 8, 8, DocumentType::SOLO_CIFRAS, false);
        $this->tipo(1, 'CE', 9, 12, DocumentType::SOLO_CIFRAS, true);
    }

    private function tipo(int $pais, string $code, int $min, int $max, string $chars, bool $deFuera): DocumentType
    {
        return DocumentType::firstOrCreate(
            ['country_id' => $pais, 'scope' => DocumentType::PERSONA, 'code' => $code],
            ['slug' => Str::random(22), 'name' => $code, 'min_length' => $min, 'max_length' => $max,
             'allowed_chars' => $chars, 'for_foreigners' => $deFuera, 'is_active' => true, 'created_by' => 1],
        );
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871236',
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function alta(array $extra): \Illuminate\Testing\TestResponse
    {
        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $cargo = Position::firstOrCreate(['code' => 'Técnico'], $this->base());

        return $this->actingAs($this->admin())
            ->post(route('business_management.people.store'), $extra + [
                'name' => 'Amit', 'lastname' => 'Sharma', 'is_active' => true,
                'company_id' => $empresa->id, 'position_id' => $cargo->id,
            ]);
    }

    /** Lo que conto el usuario: India no lleva DNI. */
    public function test_un_pais_sin_tipos_no_hereda_los_de_peru(): void
    {
        $this->alta(['country_id' => self::INDIA, 'doc_type' => 'DNI', 'num_doc' => '45871240'])
            ->assertSessionHasErrors('doc_type');

        $this->assertDatabaseMissing('people', ['num_doc' => '45871240']);
    }

    /** Y el desplegable tampoco se los ofrece: la lista de India llega vacia. */
    public function test_la_pantalla_no_ofrece_tipos_de_otro_pais(): void
    {
        $this->actingAs($this->admin())
            ->get(route('business_management.people.create'))
            ->assertInertia(function ($page) {
                $porPais = $page->toArray()['props']['docTypesByCountry'];

                $this->assertArrayHasKey(1, $porPais, 'Perú tiene tipos y no llegaron.');
                $this->assertEqualsCanonicalizing(
                    ['DNI', 'CE'],
                    array_column($porPais[1], 'value'),
                );

                $this->assertArrayNotHasKey(
                    self::INDIA,
                    $porPais,
                    'India no tiene ningún tipo sembrado y aun así le llegó una lista.',
                );
            });
    }

    /** El del pais que si los tiene sigue entrando. */
    public function test_el_tipo_del_propio_pais_pasa(): void
    {
        $this->alta(['country_id' => 1, 'doc_type' => 'DNI', 'num_doc' => '45871241'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '45871241', 'doc_type' => 'DNI']);
    }

    /**
     * Con el catalogo entero vacio si se deja pasar.
     *
     * Es la red del arranque en seco, y hay que conservarla: sin ella una base
     * recien levantada no deja dar de alta a nadie, que fue justo el motivo por
     * el que se puso.
     */
    public function test_una_base_sin_sembrar_deja_dar_de_alta(): void
    {
        DocumentType::query()->forceDelete();

        $this->alta(['country_id' => 1, 'doc_type' => 'DNI', 'num_doc' => '45871242'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '45871242']);
    }

    /**
     * Un tipo del pais de al lado tampoco vale.
     *
     * Es el mismo fallo por otra puerta: el RUN es chileno y en Peru no
     * identifica a nadie.
     */
    public function test_el_tipo_de_otro_pais_no_vale(): void
    {
        $this->tipo(self::INDIA, 'AADHAAR', 12, 12, DocumentType::SOLO_CIFRAS, false);

        $this->alta(['country_id' => 1, 'doc_type' => 'AADHAAR', 'num_doc' => '458712430'])
            ->assertSessionHasErrors('doc_type');
    }

    /**
     * El sembrado deja a cada pais con documento de persona y de empresa.
     *
     * Y se puede volver a correr: `setup:project --datos` es el unico comando
     * que se usa aqui, y siembra cada vez.
     */
    public function test_el_sembrado_da_a_cada_pais_los_suyos_y_aguanta_repetirse(): void
    {
        DocumentType::query()->forceDelete();

        DB::table('countries')->insertOrIgnore([[
            'id' => 5, 'slug' => Str::random(22), 'region_id' => 999,
            'name' => 'Chile', 'iso_code' => 'CL', 'currency' => 'CLP', 'timezone' => 'UTC',
            'default_locale_id' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]]);

        $this->seed(\Database\Seeders\DocumentTypesSeeder::class);
        $primera = DocumentType::withoutGlobalScopes()->count();

        $this->seed(\Database\Seeders\DocumentTypesSeeder::class);
        $this->assertSame($primera, DocumentType::withoutGlobalScopes()->count(), 'Sembrar dos veces duplicó filas.');

        foreach ([1 => ['DNI', 'RUC'], 5 => ['RUN', 'RUT']] as $pais => [$dePersona, $deEmpresa]) {
            $delPais = DocumentType::withoutGlobalScopes()->where('country_id', $pais)->get();

            $this->assertTrue(
                $delPais->where('scope', DocumentType::PERSONA)->where('code', $dePersona)->isNotEmpty(),
                "Al país {$pais} le falta el documento de persona {$dePersona}.",
            );
            $this->assertTrue(
                $delPais->where('scope', DocumentType::EMPRESA)->where('code', $deEmpresa)->isNotEmpty(),
                "Al país {$pais} le falta el documento de empresa {$deEmpresa}.",
            );

            // Uno y solo uno es el del propio país: es de donde sale
            // `Person::is_foreigner` desde que se borró la nacionalidad.
            $this->assertCount(
                1,
                $delPais->where('scope', DocumentType::PERSONA)->where('for_foreigners', false),
                "El país {$pais} no tiene exactamente un documento de los de casa.",
            );
        }

        // Ecuador no está en `countries` — el seeder lo pasa por alto sin
        // reventar, que es lo que tiene que hacer con cualquier país que falte.
        $this->assertSame(0, DocumentType::withoutGlobalScopes()->whereNull('country_id')->count());
    }

    /** Todo tipo sembrado dice cuánto mide su número: sin eso no se valida nada. */
    public function test_ningun_tipo_sembrado_se_queda_sin_la_regla_del_numero(): void
    {
        DocumentType::query()->forceDelete();
        $this->seed(\Database\Seeders\DocumentTypesSeeder::class);

        $mudos = DocumentType::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('min_length')->orWhereNull('max_length')->orWhereNull('allowed_chars'))
            ->pluck('code');

        $this->assertEmpty($mudos, 'Sin longitud ni caracteres, el número no se valida contra nada: ' . $mudos->implode(', '));
    }

    /**
     * Lo que YA esta guardado no se toca.
     *
     * Alguien migrado con un tipo que su pais no tiene en el catalogo tiene que
     * poder seguir corrigiendose el apellido; si no, el catalogo deja registros
     * inaccesibles detras.
     */
    public function test_el_tipo_que_la_persona_ya_lleva_no_bloquea_la_edicion(): void
    {
        $persona = Person::create($this->base() + [
            'country_id' => self::INDIA, 'name' => 'Amit', 'lastname' => 'Sharma',
            'doc_type' => 'DNI', 'num_doc' => '45871244',
        ]);

        $empresa = Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $cargo = Position::firstOrCreate(['code' => 'Técnico'], $this->base());

        $this->actingAs($this->admin())
            ->put(route('business_management.people.update', $persona), [
                'name' => 'Amit', 'lastname' => 'Sharma Gupta', 'is_active' => true,
                'country_id' => self::INDIA, 'doc_type' => 'DNI', 'num_doc' => '45871244',
                'company_id' => $empresa->id, 'position_id' => $cargo->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['id' => $persona->id, 'lastname' => 'Sharma Gupta']);
    }

    // ── El catalogo completo, tal y como lo deja `setup:project --datos` ──────

    /**
     * Los catalogos maestros, en el mismo orden en que los llama el seeder
     * grande: sin idiomas no hay locales, sin locales ni regiones no hay paises.
     *
     * Se borra antes la India de pega que monta el `setUp`: lleva el mismo ISO
     * que la India de verdad del catalogo, y dos paises vivos no pueden
     * compartir ISO.
     */
    private function sembrarLosPaises(): void
    {
        DB::table('countries')->where('id', self::INDIA)->delete();

        $this->seed(\Database\Seeders\LanguagesSeeder::class);
        $this->seed(\Database\Seeders\RegionsSeeder::class);
        $this->seed(\Database\Seeders\LocalesSeeder::class);
        $this->seed(\Database\Seeders\CountriesSeeder::class);
    }

    /** No falta ni un pais de Latinoamerica en el catalogo. */
    public function test_latinoamerica_entera_esta_en_el_catalogo_de_paises(): void
    {
        $this->sembrarLosPaises();

        $sembrados = DB::table('countries')->whereNull('deleted_at')->pluck('name', 'iso_code');

        foreach (array_keys(self::LATINOAMERICA) as $iso) {
            $this->assertArrayHasKey(
                $iso,
                $sembrados->all(),
                "{$iso} no está en el catálogo de países, así que no se puede elegir ni tener documentos.",
            );
        }
    }

    /** Y los que ya estaban siguen donde estaban. */
    public function test_los_ids_de_los_paises_que_ya_estaban_no_se_mueven(): void
    {
        $this->sembrarLosPaises();

        foreach (self::IDS_QUE_NO_SE_MUEVEN as $id => $iso) {
            $this->assertSame(
                $iso,
                DB::table('countries')->where('id', $id)->value('iso_code'),
                "El id {$id} dejó de ser {$iso}: los planes y las personas que apuntan ahí cambiaron de país solos.",
            );
        }
    }

    /**
     * Cada pais de Latinoamerica sale del sembrado con lo suyo: el documento del
     * que es de casa, el del que viene de fuera y el de la empresa.
     *
     * Y ninguno se queda sin decir cuanto mide su numero ni que caracteres
     * admite: una fila asi no valida nada, deja pasar un numero de celular
     * tecleado en el campo del documento.
     */
    public function test_cada_pais_de_latinoamerica_tiene_su_documento_de_persona_y_de_empresa(): void
    {
        $this->sembrarLosPaises();
        DocumentType::query()->forceDelete();
        $this->seed(\Database\Seeders\DocumentTypesSeeder::class);

        $paises = DB::table('countries')->pluck('id', 'iso_code');

        foreach (self::LATINOAMERICA as $iso => [$deCasa, $deEmpresa]) {
            $tipos = DocumentType::withoutGlobalScopes()->where('country_id', $paises[$iso])->get();

            $personas = $tipos->where('scope', DocumentType::PERSONA);
            $empresas = $tipos->where('scope', DocumentType::EMPRESA);

            $this->assertTrue(
                $personas->where('code', $deCasa)->where('for_foreigners', false)->isNotEmpty(),
                "A {$iso} le falta {$deCasa}, que es el documento del que es de allí.",
            );

            // Uno y solo uno es el de casa: de ahi sale `Person::is_foreigner`
            // desde que se borro la nacionalidad, y con dos no se sabria cual
            // manda.
            $this->assertCount(
                1,
                $personas->where('for_foreigners', false),
                "{$iso} no tiene exactamente un documento de los de casa: " . $personas->pluck('code')->implode(', '),
            );

            $this->assertTrue(
                $personas->where('for_foreigners', true)->isNotEmpty(),
                "A {$iso} no le queda ningún documento para quien viene de fuera.",
            );

            $this->assertTrue(
                $empresas->where('code', $deEmpresa)->isNotEmpty(),
                "A {$iso} le falta {$deEmpresa}: sus empresas no se podrían dar de alta.",
            );

            foreach ($tipos as $tipo) {
                $this->assertNotNull($tipo->min_length, "{$iso}/{$tipo->code} no dice cuánto mide su número.");
                $this->assertNotNull($tipo->max_length, "{$iso}/{$tipo->code} no dice cuánto mide su número.");
                $this->assertContains(
                    $tipo->allowed_chars,
                    [DocumentType::SOLO_CIFRAS, DocumentType::CIFRAS_Y_LETRAS],
                    "{$iso}/{$tipo->code} no dice qué caracteres admite.",
                );
                $this->assertLessThanOrEqual(
                    $tipo->max_length,
                    $tipo->min_length,
                    "{$iso}/{$tipo->code} pide un mínimo mayor que su máximo: no lo cumple ningún número.",
                );
            }
        }
    }

    /**
     * Sembrar el mundo entero dos veces no duplica ni una fila.
     *
     * `setup:project --datos` es el unico comando que se corre aqui y siembra
     * cada vez que se corre. Con veintiun paises y sus tres o cuatro tipos cada
     * uno, un duplicado no se ve a ojo: se ve en el desplegable, con el DNI
     * repetido dos veces.
     */
    public function test_sembrar_latinoamerica_dos_veces_no_duplica_ni_una_fila(): void
    {
        $this->sembrarLosPaises();
        DocumentType::query()->forceDelete();

        $this->seed(\Database\Seeders\DocumentTypesSeeder::class);
        $tiposTrasLaPrimera  = DocumentType::withoutGlobalScopes()->count();
        $paisesTrasLaPrimera = DB::table('countries')->whereNull('deleted_at')->count();

        $this->sembrarLosPaises();
        $this->seed(\Database\Seeders\DocumentTypesSeeder::class);

        $this->assertSame(
            $tiposTrasLaPrimera,
            DocumentType::withoutGlobalScopes()->count(),
            'Sembrar dos veces duplicó tipos de documento.',
        );
        $this->assertSame(
            $paisesTrasLaPrimera,
            DB::table('countries')->whereNull('deleted_at')->count(),
            'Sembrar dos veces duplicó países.',
        );

        // Y ninguna pareja pais + ambito + codigo aparece dos veces, que es la
        // forma en que se duplicaria sin que cambiara el total si algo mas
        // desapareciera a la vez.
        $repetidos = DocumentType::withoutGlobalScopes()
            ->selectRaw('country_id, scope, code, COUNT(*) as veces')
            ->groupBy('country_id', 'scope', 'code')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertEmpty($repetidos, 'Hay tipos repetidos dentro del mismo país: ' . $repetidos->pluck('code')->implode(', '));
    }
}
