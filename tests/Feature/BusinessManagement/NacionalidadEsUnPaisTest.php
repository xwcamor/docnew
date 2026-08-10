<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Country;
use App\Models\Person;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Una nacionalidad ES un pais, y ya no hay un catalogo aparte para eso.
 *
 * Habia dos tablas para lo mismo: `countries` con 26 filas —nombre, ISO,
 * moneda, huso— y `nationalities` con cuatro (Peru, Venezuela, Chile,
 * Argentina), un modulo CRUD completo detras y 35 archivos del proyecto
 * mencionandola.
 *
 * Y casi cuesta caro: el tipo de documento se deducia comparando el TEXTO del
 * nombre de la nacionalidad con el del pais. Bastaba con que la fila se hubiera
 * sembrado como «Peruana» en vez de «Perú» para que los 224 peruanos salieran
 * con carne de extranjeria en vez de DNI. Se salvo por casualidad.
 *
 * Ahora se comparan NUMEROS, que es lo que se comprueba aqui.
 */
class NacionalidadEsUnPaisTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871236',
        ]);
    }

    private function venezuela(): Country
    {
        return Country::firstOrCreate(
            ['iso_code' => 'VE'],
            ['slug' => Str::random(22), 'region_id' => 999, 'name' => 'Venezuela',
             'currency' => 'VES', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true],
        );
    }

    public function test_la_tabla_de_nacionalidades_ya_no_existe(): void
    {
        $this->assertFalse(
            Schema::hasTable('nationalities'),
            'Sigue habiendo un catalogo de nacionalidades aparte del de paises.',
        );
    }

    /** La nacionalidad apunta a un pais de verdad, con su nombre y su ISO. */
    public function test_la_nacionalidad_de_una_persona_es_un_pais(): void
    {
        $persona = Person::create($this->base() + [
            'name' => 'Luis', 'lastname' => 'Pérez', 'doc_type' => 'CE', 'num_doc' => '001234567',
            'nationality_id' => $this->venezuela()->id,
        ]);

        $this->assertInstanceOf(Country::class, $persona->fresh()->nationality);
        $this->assertSame('Venezuela', $persona->fresh()->nationality->name);
    }

    /**
     * Lo que importa en la puerta: quien viene de fuera.
     *
     * La nacionalidad solo se enseña cuando NO es la del pais donde se trabaja.
     * Con el 97 % peruanos, marcarlos a todos es la misma bandera repetida y el
     * ojo deja de verla; lo que hay que ver es el que lleva carne y no DNI.
     */
    public function test_solo_se_marca_al_que_viene_de_fuera(): void
    {
        $deAqui = Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871237',
            'nationality_id' => 1,
        ]);

        $deFuera = Person::create($this->base() + [
            'name' => 'Luis', 'lastname' => 'Pérez', 'doc_type' => 'CE', 'num_doc' => '001234568',
            'nationality_id' => $this->venezuela()->id,
        ]);

        $this->assertNull($deAqui->foreign_nationality);
        $this->assertFalse($deAqui->is_foreigner);

        $this->assertSame('Venezuela', $deFuera->foreign_nationality);
        $this->assertTrue($deFuera->is_foreigner);
    }

    /** Sin nacionalidad no se marca nada: es un campo opcional. */
    public function test_sin_nacionalidad_no_se_marca_nada(): void
    {
        $persona = $this->unaFila();

        $this->assertNull($persona->foreign_nationality);
        $this->assertFalse($persona->is_foreigner);
    }

    /** El alta admite un pais como nacionalidad, y rechaza un id inventado. */
    public function test_el_alta_valida_la_nacionalidad_contra_los_paises(): void
    {
        $empresa = \App\Models\Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $cargo = \App\Models\Position::firstOrCreate(
            ['code' => 'Técnico'],
            $this->base() + ['is_signature_approver' => false],
        );

        $datos = [
            'name' => 'Luis', 'lastname' => 'Pérez', 'doc_type' => 'DNI', 'num_doc' => '45871238',
            'country_id' => 1, 'is_active' => true, 'roles' => ['worker'],
            'company_id' => $empresa->id, 'position_id' => $cargo->id,
        ];

        $this->actingAs($this->admin())
            ->post(route('business_management.people.store'), $datos + ['nationality_id' => $this->venezuela()->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post(route('business_management.people.store'),
                ['num_doc' => '45871239', 'nationality_id' => 99999] + $datos)
            ->assertSessionHasErrors('nationality_id');
    }
}
