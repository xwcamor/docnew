<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\DocumentType;
use App\Models\Person;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * La nacionalidad ya no existe, y no hace falta.
 *
 * Habia dos tablas para lo mismo: `countries` con 26 filas —nombre, ISO,
 * moneda, huso— y `nationalities` con cuatro, con un modulo CRUD completo
 * detras. Se borro la tabla y la nacionalidad paso a apuntar a `countries`.
 *
 * Y despues se vio que sobraba tambien la columna. Ya estaba el pais —el del
 * documento, que ademas forma parte de la clave unica— y ya estaba el tipo de
 * documento, que en Peru dice quien es extranjero sin margen: un peruano lleva
 * DNI y quien viene de fuera lleva carne de extranjeria, PTP o pasaporte.
 *
 * Existia en la v1 porque alli NO habia tipo de documento: `workers.num_doc`
 * era texto pelado y la nacionalidad era la unica forma de saber quien llevaba
 * carne. De hecho el tipo se DEDUJO de ella al migrar los 391. Cumplio su
 * funcion y el que sobraba paso a ser ella.
 */
class NacionalidadEsUnPaisTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            ['DNI', 8, 8, false],
            ['CE', 9, 12, true],
            ['PASAPORTE', 6, 20, true],
        ] as [$code, $min, $max, $deFuera]) {
            DocumentType::firstOrCreate(
                ['country_id' => 1, 'scope' => DocumentType::PERSONA, 'code' => $code],
                ['slug' => Str::random(22), 'name' => $code,
                 'min_length' => $min, 'max_length' => $max,
                 'allowed_chars' => $code === 'PASAPORTE' ? DocumentType::CIFRAS_Y_LETRAS : DocumentType::SOLO_CIFRAS,
                 'for_foreigners' => $deFuera, 'is_active' => true, 'created_by' => 1],
            );
        }
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871236',
        ]);
    }

    public function test_la_tabla_de_nacionalidades_ya_no_existe(): void
    {
        $this->assertFalse(
            Schema::hasTable('nationalities'),
            'Sigue habiendo un catalogo de nacionalidades aparte del de paises.',
        );
    }

    /** Ni la columna: era la misma pregunta que el tipo de documento. */
    public function test_la_columna_de_nacionalidad_tampoco(): void
    {
        $this->assertFalse(
            Schema::hasColumn('people', 'nationality_id'),
            'La persona sigue guardando una nacionalidad que el documento ya dice.',
        );
    }

    /**
     * Lo que importa en la puerta: quien viene de fuera.
     *
     * Y lo dice el documento. Con el 97 % peruanos, marcarlos a todos es la
     * misma bandera repetida y el ojo deja de verla; lo que hay que ver es el
     * que lleva carne y no DNI.
     */
    public function test_el_documento_dice_quien_viene_de_fuera(): void
    {
        $deAqui = Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871237',
        ]);

        $deFuera = Person::create($this->base() + [
            'name' => 'Luis', 'lastname' => 'Pérez', 'doc_type' => 'CE', 'num_doc' => '001234568',
        ]);

        $this->assertFalse($deAqui->is_foreigner);
        $this->assertTrue($deFuera->is_foreigner);
    }

    /** El pasaporte tambien: un peruano en obra se identifica con su DNI. */
    public function test_el_pasaporte_cuenta_como_de_fuera(): void
    {
        $persona = Person::create($this->base() + [
            'name' => 'John', 'lastname' => 'Smith', 'doc_type' => 'PASAPORTE', 'num_doc' => 'AB123456',
        ]);

        $this->assertTrue($persona->is_foreigner);
    }

    /**
     * Un tipo que no esta en el catalogo no convierte a nadie en extranjero.
     *
     * Pasa con lo migrado de la v1: alli el tipo se dedujo, y si alguien quedo
     * con una sigla que el catalogo de su pais no tiene, lo prudente es no
     * marcarlo — es lo que vale para el 97 % — en vez de inventarse la
     * respuesta.
     */
    public function test_un_tipo_fuera_del_catalogo_no_marca_a_nadie(): void
    {
        $persona = Person::create($this->base() + [
            'name' => 'Rara', 'lastname' => 'Migrada', 'doc_type' => 'PTP', 'num_doc' => '001234569',
        ]);

        $this->assertFalse($persona->is_foreigner);
    }

    /** El alta ya no pide nacionalidad, y mandarla no rompe nada. */
    public function test_el_alta_no_pide_nacionalidad(): void
    {
        $empresa = \App\Models\Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $cargo = \App\Models\Position::firstOrCreate(['code' => 'Técnico'], $this->base());

        $this->actingAs($this->admin())
            ->post(route('business_management.people.store'), [
                'name' => 'Luis', 'lastname' => 'Pérez', 'doc_type' => 'DNI', 'num_doc' => '45871238',
                'country_id' => 1, 'is_active' => true,
                'company_id' => $empresa->id, 'position_id' => $cargo->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '45871238']);
    }
}
