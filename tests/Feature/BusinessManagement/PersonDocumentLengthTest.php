<?php

namespace Tests\Feature\BusinessManagement;

use App\Imports\BusinessManagement\People\PeopleImport;
use App\Models\DocumentType;
use App\Models\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * El documento tiene que tener el largo de su tipo, por las DOS puertas.
 *
 * Un DNI peruano son ocho digitos. En la base maestra habia uno de nueve que
 * resulto ser un numero de celular tecleado en el campo del documento, y a esa
 * persona la migracion la traia como alguien distinto de si misma. El
 * formulario ya lo comprobaba al guardar; el Excel no, y por ahi entraba lo
 * mismo sin que nadie lo viera.
 *
 * El largo se aplica al dar de alta, NO al buscar: la cuadrilla se busca por
 * coincidencia exacta del numero, asi que alguien ya migrado con un documento
 * raro se sigue encontrando aunque hoy no se pudiera dar de alta.
 */
class PersonDocumentLengthTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'people.view_private_info', 'guard_name' => 'web']);

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'DNI'],
            ['slug' => Str::random(22), 'name' => 'Documento Nacional de Identidad',
             'min_length' => 8, 'max_length' => 8, 'allowed_chars' => DocumentType::SOLO_CIFRAS,
             'is_active' => true, 'created_by' => 1],
        );
        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'CE'],
            ['slug' => Str::random(22), 'name' => 'Carné de Extranjería',
             'min_length' => 9, 'max_length' => 12, 'allowed_chars' => DocumentType::SOLO_CIFRAS,
             'is_active' => true, 'created_by' => 1],
        );
        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'PASAPORTE'],
            ['slug' => Str::random(22), 'name' => 'Pasaporte',
             'min_length' => 6, 'max_length' => 20, 'allowed_chars' => DocumentType::CIFRAS_Y_LETRAS,
             'is_active' => true, 'created_by' => 1],
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
        $empresa = \App\Models\Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        $cargo = \App\Models\Position::firstOrCreate(
            ['code' => 'Técnico'],
            $this->base(),
        );

        return $this->actingAs($this->admin())
            ->post(route('business_management.people.store'), $extra + [
                'name' => 'Edison Yosimar', 'lastname' => 'Rosales Capcha',
                'country_id' => 1, 'is_active' => true,
                'company_id' => $empresa->id, 'position_id' => $cargo->id,
            ]);
    }

    /** El caso real: un celular de nueve digitos en el campo del DNI. */
    public function test_no_se_da_de_alta_un_dni_de_nueve_digitos(): void
    {
        $this->alta(['doc_type' => 'DNI', 'num_doc' => '946733493'])
            ->assertSessionHasErrors('num_doc');

        $this->assertSinPersonaConDocumento('946733493');
    }

    public function test_tampoco_uno_corto(): void
    {
        $this->alta(['doc_type' => 'DNI', 'num_doc' => '4567123'])
            ->assertSessionHasErrors('num_doc');
    }

    public function test_un_dni_de_ocho_entra(): void
    {
        $this->alta(['doc_type' => 'DNI', 'num_doc' => '43673535'])
            ->assertSessionHasNoErrors();

        $this->assertPersonaConDocumento('43673535');
    }

    /** El extranjero lleva nueve, y con su tipo el mismo numero si entra. */
    public function test_el_carne_de_extranjeria_admite_nueve(): void
    {
        $this->alta(['doc_type' => 'CE', 'num_doc' => '003028674'])
            ->assertSessionHasNoErrors();

        $this->assertPersonaConDocumento('003028674', ['doc_type' => 'CE']);
    }

    /**
     * El largo cuadra y el contenido no: ocho caracteres, pero uno es letra.
     *
     * Sin esto, la regla de longitud sola dejaba pasar «1111111A» — que es
     * exactamente la forma que tenia el «11111111» inventado de la v1.
     */
    public function test_un_dni_con_letras_no_entra_aunque_mida_ocho(): void
    {
        $this->alta(['doc_type' => 'DNI', 'num_doc' => '1111111A'])
            ->assertSessionHasErrors('num_doc');

        $this->assertSinPersonaConDocumento('1111111A');
    }

    /** Y el pasaporte si las lleva: la regla es por tipo, no una para todos. */
    public function test_el_pasaporte_si_admite_letras(): void
    {
        $this->alta(['doc_type' => 'PASAPORTE', 'num_doc' => 'AB123456'])
            ->assertSessionHasNoErrors();

        $this->assertPersonaConDocumento('AB123456', ['doc_type' => 'PASAPORTE']);
    }

    /** @param array<int, array<string, string>> $filas */
    private function importar(array $filas): PeopleImport
    {
        $import = new PeopleImport('update_or_create', false);
        $import->collection(new Collection($filas));

        return $import;
    }

    /** La misma regla por Excel: si no, se arregla una puerta y se deja la otra. */
    public function test_el_excel_rechaza_la_fila_del_documento_largo(): void
    {
        $this->actingAs($this->admin());

        $import = $this->importar([
            ['doc_type' => 'DNI', 'num_doc' => '946733493', 'name' => 'Edison', 'lastname' => 'Rosales Capcha'],
        ]);

        $this->assertCount(1, $import->errors);
        $this->assertSame(0, $import->created);
        $this->assertSinPersonaConDocumento('946733493');
    }

    /** Por Excel tampoco entran las letras en un DNI. */
    public function test_el_excel_rechaza_el_dni_con_letras(): void
    {
        $this->actingAs($this->admin());

        $import = $this->importar([
            ['doc_type' => 'DNI', 'num_doc' => '1111111A', 'name' => 'Falso', 'lastname' => 'Inventado'],
        ]);

        $this->assertCount(1, $import->errors);
        $this->assertSame(0, $import->created);
    }

    /** Y no se lleva por delante las filas buenas del mismo fichero. */
    public function test_la_fila_mala_no_tumba_a_las_buenas(): void
    {
        $this->actingAs($this->admin());

        // El alta exige empresa y cargo desde que el import dejo de crear
        // personas sin vinculo (ver PeopleImportTest): sin ellos fallarian las
        // tres filas y el test no probaria lo que dice probar.
        \App\Models\Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
        \App\Models\Position::firstOrCreate(['code' => 'Técnico'], $this->base());

        $dondeTrabaja = ['company' => '20100000001', 'position' => 'Técnico'];

        $import = $this->importar([
            ['doc_type' => 'DNI', 'num_doc' => '43673535',  'name' => 'Edison', 'lastname' => 'Rosales Capcha'] + $dondeTrabaja,
            ['doc_type' => 'DNI', 'num_doc' => '11111',     'name' => 'Falso',  'lastname' => 'Inventado'] + $dondeTrabaja,
            ['doc_type' => 'DNI', 'num_doc' => '45871237',  'name' => 'Ana',    'lastname' => 'Quispe'] + $dondeTrabaja,
        ]);

        $this->assertCount(1, $import->errors);
        $this->assertSame(2, $import->created);
        $this->assertPersonaConDocumento('43673535');
        $this->assertPersonaConDocumento('45871237');
    }
}
