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
}
