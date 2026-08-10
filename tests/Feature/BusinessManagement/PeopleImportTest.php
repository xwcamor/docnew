<?php

namespace Tests\Feature\BusinessManagement;

use App\Imports\BusinessManagement\People\PeopleImport;
use App\Models\Company;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\Position;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * El importador de personas, que era la puerta floja del modulo.
 *
 * Por pantalla el alta exige empresa y cargo —en la v1 las dos son NOT NULL en
 * `workers`— y por Excel entraban personas sin ninguna de las dos y sin rol en
 * obra: filas que no se pueden meter en ningun plan y que hay que arreglar a
 * mano una por una. Una regla que solo vale en una de las dos puertas no es una
 * regla.
 *
 * Y el lookup de «esta persona ya existe» no miraba el pais, cuando la unicidad
 * declarada en la base es (tenant, pais, tipo, numero).
 */
class PeopleImportTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'DNI'],
            ['slug' => Str::random(22), 'name' => 'Documento Nacional de Identidad',
             'min_length' => 8, 'max_length' => 8, 'allowed_chars' => DocumentType::SOLO_CIFRAS,
             'is_active' => true, 'created_by' => 1],
        );
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871200',
        ]);
    }

    private function empresa(): Company
    {
        return Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
    }

    private function cargo(string $code = 'Técnico'): Position
    {
        return Position::firstOrCreate(['code' => $code], $this->base());
    }

    private function venezuela(): Country
    {
        return Country::firstOrCreate(
            ['iso_code' => 'VE'],
            ['slug' => Str::random(22), 'region_id' => 999, 'name' => 'Venezuela',
             'currency' => 'VES', 'timezone' => 'UTC', 'default_locale_id' => 1, 'is_active' => true],
        );
    }

    /** @param array<int, array<string, mixed>> $filas */
    private function importar(array $filas, string $mode = 'update_or_create'): PeopleImport
    {
        $import = new PeopleImport($mode, false);
        $import->collection(new Collection($filas));

        return $import;
    }

    /** La fila minima que crea a alguien de verdad. */
    private function fila(array $extra = []): array
    {
        return $extra + [
            'doc_type' => 'DNI', 'num_doc' => '45871236',
            'name' => 'Juan Carlos', 'lastname' => 'Pérez Gómez',
            'company' => '20100000001', 'position' => 'Técnico',
        ];
    }

    /**
     * Lo que importaba de verdad: la persona llega con su empresa y su cargo.
     *
     * Sin la fila de `person_company_links` la persona no aparece al armar un
     * plan — el vinculo es lo unico que dice para quien trabaja.
     */
    public function test_el_alta_por_excel_deja_la_empresa_y_el_cargo_puestos(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $cargo   = $this->cargo();

        $import = $this->importar([$this->fila()]);

        $this->assertSame([], $import->errors);
        $this->assertSame(1, $import->created);

        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $this->assertDatabaseHas('person_company_links', [
            'person_id' => $persona->id, 'company_id' => $empresa->id,
            'position_id' => $cargo->id, 'is_active' => true,
        ]);
    }

    /**
     * Y SIN roles, que es lo correcto.
     *
     * Los roles dicen que APRUEBA la persona en el flujo, y quien entra por
     * Excel suele ser trabajador de una contratista, que no aprueba nada. Antes
     * nacian con «trabajador», un rol que tenia el 100 % de la gente y por
     * tanto no separaba nada.
     */
    public function test_el_alta_por_excel_no_inventa_roles(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $this->importar([$this->fila()]);

        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $this->assertSame(0, $persona->roles()->count());
    }

    /** Los roles del fichero se leen como los ve el usuario en pantalla. */
    public function test_los_roles_se_leen_en_castellano_y_separados_por_comas(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $this->importar([$this->fila(['roles' => 'Supervisor, Supervisor HSE'])]);

        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $this->assertTrue($persona->hasRole(PersonRole::SUPERVISOR));
        $this->assertTrue($persona->hasRole(PersonRole::HSE_SUPERVISOR));
    }

    /** Un rol que no existe se dice, no se traga en silencio. */
    public function test_un_rol_inventado_para_la_fila(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $import = $this->importar([$this->fila(['roles' => 'Capataz'])]);

        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('Capataz', $import->errors[0]['message']);
        $this->assertSame(0, $import->created);
    }

    /** Sin empresa ni cargo no se da de alta: igual que por pantalla. */
    public function test_sin_empresa_ni_cargo_no_entra(): void
    {
        $this->actingAs($this->admin());

        $import = $this->importar([[
            'doc_type' => 'DNI', 'num_doc' => '45871236',
            'name' => 'Juan Carlos', 'lastname' => 'Pérez Gómez',
        ]]);

        $this->assertCount(1, $import->errors);
        $this->assertSame(0, $import->created);
        $this->assertDatabaseMissing('people', ['num_doc' => '45871236']);
    }

    /** Una empresa que no esta en el catalogo se nombra en el error. */
    public function test_una_empresa_que_no_existe_se_dice_por_su_nombre(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $import = $this->importar([$this->fila(['company' => 'Fantasma SAC'])]);

        $this->assertCount(1, $import->errors);
        $this->assertStringContainsString('Fantasma SAC', $import->errors[0]['message']);
    }

    /** La empresa se busca por RUC, por nombre y por razon social. */
    public function test_la_empresa_se_encuentra_por_el_nombre_sin_tildes_ni_mayusculas(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $this->cargo();

        $this->importar([$this->fila(['company' => '  contratista   sac '])]);

        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $this->assertDatabaseHas('person_company_links', [
            'person_id' => $persona->id, 'company_id' => $empresa->id,
        ]);
    }

    /**
     * El caso que costo horas en la base maestra: `06842865` es un DNI real y
     * Excel lo guarda como 6842865 en cuanto la celda es de tipo numero.
     */
    public function test_a_un_dni_numerico_se_le_devuelve_el_cero_de_delante(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $import = $this->importar([$this->fila(['num_doc' => 6842865])]);

        $this->assertSame([], $import->errors);
        $this->assertDatabaseHas('people', ['num_doc' => '06842865']);
        $this->assertStringContainsString('06842865', $import->preview[0]['notice']);
    }

    /**
     * Pero solo si vino como numero. Un «6842865» tecleado como TEXTO se
     * rechaza: ahi el que se equivoco fue quien lo escribio, y rellenarlo seria
     * inventarse un documento.
     */
    public function test_un_dni_corto_escrito_a_mano_no_se_rellena(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $import = $this->importar([$this->fila(['num_doc' => '6842865'])]);

        $this->assertCount(1, $import->errors);
        $this->assertDatabaseMissing('people', ['num_doc' => '06842865']);
    }

    /**
     * La unicidad declarada en la base es (tenant, pais, tipo, numero) y el
     * lookup se saltaba el pais: un pasaporte de otro pais con el mismo numero
     * pisaba al de aqui en vez de crear a nadie.
     */
    public function test_el_documento_identifica_dentro_de_su_pais(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $deFuera = Person::create(['country_id' => $this->venezuela()->id] + $this->base() + [
            'name' => 'Otro', 'lastname' => 'Distinto',
            'doc_type' => 'DNI', 'num_doc' => '45871236',
        ]);

        $import = $this->importar([$this->fila()]);

        $this->assertSame(1, $import->created, 'La fila pisó a alguien de otro país en vez de crear.');
        $this->assertSame('Otro', $deFuera->fresh()->name);
        $this->assertSame(2, Person::where('num_doc', '45871236')->count());
    }

    /** La fecha de nacimiento admite el formato de la plantilla y el de Excel. */
    public function test_la_fecha_de_nacimiento_admite_texto_y_numero_de_excel(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $this->importar([
            $this->fila(['birthdate' => '1990-05-14']),
            $this->fila(['num_doc' => '45871237', 'birthdate' => 33007]), // 14/05/1990 en Excel
        ]);

        $this->assertSame('1990-05-14', Person::where('num_doc', '45871236')->firstOrFail()->birthdate->toDateString());
        $this->assertSame('1990-05-14', Person::where('num_doc', '45871237')->firstOrFail()->birthdate->toDateString());
    }

    /** Una fecha de nacimiento en el futuro no es una fecha de nacimiento. */
    public function test_una_fecha_de_nacimiento_futura_para_la_fila(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $import = $this->importar([$this->fila(['birthdate' => now()->addYear()->toDateString()])]);

        $this->assertCount(1, $import->errors);
        $this->assertSame(0, $import->created);
    }

    /**
     * El fichero de correccion de nombres —el caso real: los 13 cruzados de la
     * v1— no trae la plantilla entera, y la columna en blanco no debe borrar el
     * vinculo que ya tenia la persona.
     */
    public function test_la_columna_en_blanco_no_toca_el_vinculo_que_ya_estaba(): void
    {
        $this->actingAs($this->admin());
        $empresa = $this->empresa();
        $this->cargo();

        $this->importar([$this->fila()]);
        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $import = $this->importar([[
            'doc_type' => 'DNI', 'num_doc' => '45871236',
            'name' => 'Juan Carlos', 'lastname' => 'Pérez Gómez Corregido',
        ]]);

        $this->assertSame(1, $import->updated);
        $this->assertSame('Pérez Gómez Corregido', $persona->fresh()->lastname);
        $this->assertDatabaseHas('person_company_links', [
            'person_id' => $persona->id, 'company_id' => $empresa->id, 'is_active' => true,
        ]);
    }

    /**
     * El fichero de verdad, por la ruta de verdad.
     *
     * Los demas tests llaman a `collection()` con arrays y se saltan lo que
     * hace Maatwebsite antes: leer la primera fila como cabecera y decidir como
     * se llama cada columna. Si la plantilla y el importador dejaran de
     * entenderse, ninguno de ellos se enteraria.
     */
    public function test_un_xlsx_de_verdad_entra_por_la_pantalla(): void
    {
        $this->empresa();
        $this->cargo();

        $hoja = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja->getActiveSheet()->fromArray([
            ['doc_type', 'num_doc', 'name', 'lastname', 'company', 'position', 'roles', 'birthdate'],
            ['DNI', '45871236', 'Juan Carlos', 'Pérez Gómez', '20100000001', 'Técnico', '', '1990-05-14'],
        ]);

        $ruta = tempnam(sys_get_temp_dir(), 'personas') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($hoja))->save($ruta);

        // Primero la previsualizacion: enseña lo que va a pasar y no escribe.
        $preview = $this->actingAs($this->admin())->post(route('business_management.people.import'), [
            'file' => new \Illuminate\Http\UploadedFile($ruta, 'personas.xlsx', null, null, true),
            'dry_run' => true,
        ]);

        $preview->assertOk();
        $this->assertSame(1, $preview->json('summary.created'));
        $this->assertSame(0, $preview->json('summary.error_count'), 'La plantilla y el importador no se entienden.');
        $this->assertDatabaseMissing('people', ['num_doc' => '45871236']);

        // Y ahora el commit.
        $this->actingAs($this->admin())->post(route('business_management.people.import'), [
            'file' => new \Illuminate\Http\UploadedFile($ruta, 'personas.xlsx', null, null, true),
            'dry_run' => false,
        ])->assertOk();

        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $this->assertSame('1990-05-14', $persona->birthdate->toDateString());
        $this->assertDatabaseHas('person_company_links', ['person_id' => $persona->id]);

        @unlink($ruta);
    }

    /**
     * La plantilla que se descarga tiene que poder volver a entrar.
     *
     * Es la unica prueba que cubre las dos mitades a la vez: si alguien añade
     * una columna al importador y se olvida de la plantilla —o al reves— el
     * usuario se descarga un fichero que su propio sistema rechaza.
     */
    public function test_la_plantilla_que_se_descarga_la_admite_el_importador(): void
    {
        $this->empresa();
        $this->cargo();
        $this->cargo('Supervisor');
        $this->venezuela();

        $descarga = $this->actingAs($this->admin())
            ->get(route('business_management.people.import_template'))
            ->assertOk();

        $ruta = tempnam(sys_get_temp_dir(), 'plantilla') . '.xlsx';
        file_put_contents($ruta, $descarga->streamedContent());

        $respuesta = $this->actingAs($this->admin())->post(route('business_management.people.import'), [
            'file' => new \Illuminate\Http\UploadedFile($ruta, 'plantilla.xlsx', null, null, true),
            'dry_run' => true,
        ]);

        $respuesta->assertOk();
        $this->assertSame(
            0,
            $respuesta->json('summary.error_count'),
            'La plantilla trae filas que el importador rechaza: ' . json_encode($respuesta->json('summary.errors')),
        );
        $this->assertSame(2, $respuesta->json('summary.created'));

        @unlink($ruta);
    }

    /** Y si la trae, la cambia: la persona se mudo de contratista. */
    public function test_una_empresa_distinta_añade_el_vinculo_nuevo(): void
    {
        $this->actingAs($this->admin());
        $this->empresa();
        $this->cargo();

        $this->importar([$this->fila()]);
        $persona = Person::where('num_doc', '45871236')->firstOrFail();

        $otra = Company::firstOrCreate(
            ['num_doc' => '20100000002'],
            $this->base() + ['name' => 'Otra Contratista', 'complete_name' => 'Otra Contratista SAC'],
        );

        $this->importar([$this->fila(['company' => '20100000002', 'position' => 'Técnico'])]);

        $this->assertDatabaseHas('person_company_links', [
            'person_id' => $persona->id, 'company_id' => $otra->id, 'is_active' => true,
        ]);
    }
}
