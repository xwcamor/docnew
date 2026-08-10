<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Personas: el modulo donde estan los documentos de identidad de 391
 * trabajadores reales.
 *
 * Extiende `CatalogTestCase` por el escenario y las pruebas comunes —que las
 * pantallas abren, que el candado aguanta— y añade lo suyo, que es casi todo
 * sobre el documento: que no sale entero sin permiso y, sobre todo, que no se
 * pierde al guardar.
 */
class PeopleCrudTest extends CatalogTestCase
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
             'min_length' => 7, 'max_length' => 8, 'is_active' => true, 'created_by' => 1],
        );
        DocumentType::firstOrCreate(
            ['country_id' => 1, 'code' => 'CE'],
            ['slug' => Str::random(22), 'name' => 'Carné de Extranjería',
             'min_length' => 9, 'max_length' => 12, 'is_active' => true, 'created_by' => 1],
        );
    }

    private function empresa(): Company
    {
        return Company::firstOrCreate(
            ['num_doc' => '20100000001'],
            $this->base() + ['name' => 'Contratista', 'complete_name' => 'Contratista SAC'],
        );
    }

    private function cargo(): Position
    {
        return Position::firstOrCreate(
            ['code' => 'Técnico'],
            $this->base(),
        );
    }

    /** Una nacionalidad es un PAIS: no hay catalogo aparte. */
    private function persona(string $numDoc = '47019236'): Person
    {
        return Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => $numDoc,
            'name' => 'Ana', 'lastname' => 'Quispe', 'is_active' => true,
        ]);
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return $this->persona();
    }

    /** Lo que manda el formulario cuando esta entero. */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'name'        => 'Luis',
            'lastname'    => 'Ramírez',
            'doc_type'    => 'DNI',
            'num_doc'     => '10203040',
            'country_id'  => 1,
            'company_id'  => $this->empresa()->id,
            'position_id' => $this->cargo()->id,
            'is_active'   => true,
        ], $extra);
    }

    /** Un usuario con los permisos que se le pasen y nada mas. */
    private function usuario(array $permisos): User
    {
        $rol = Role::firstOrCreate(['name' => 'r' . Str::random(6), 'guard_name' => 'web'], ['description' => 'x']);
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);
        $u->givePermissionTo($permisos);

        return $u;
    }

    // ── Alta y edicion ───────────────────────────────────────────────────────

    /**
     * El alta guarda la persona **y** lo que no es la persona: su empresa, su
     * cargo y su rol en obra, que viven en otras dos tablas.
     */
    public function test_un_alta_guarda_la_persona_su_vinculo_y_su_rol(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->formulario([
            'birthdate' => '1990-05-14',
            'roles'     => [PersonRole::SUPERVISOR, PersonRole::HSE_SUPERVISOR],
        ]))->assertRedirect(route('business_management.people.index'));

        $persona = Person::where('num_doc', '10203040')->first();
        $this->assertNotNull($persona, 'no se guardó la persona');
        $this->assertSame('Ramírez', $persona->lastname);
        $this->assertSame('1990-05-14', $persona->birthdate->format('Y-m-d'));

        $this->assertDatabaseHas('person_company_links', [
            'person_id'   => $persona->id,
            'company_id'  => $this->empresa()->id,
            'position_id' => $this->cargo()->id,
        ]);

        $this->assertEqualsCanonicalizing(
            [PersonRole::SUPERVISOR, PersonRole::HSE_SUPERVISOR],
            $persona->roles()->where('is_active', true)->pluck('role')->all(),
        );
    }

    /**
     * Se puede dar de alta a un supervisor.
     *
     * Parece obvio y no lo era: el rol vive en `person_roles`, ninguna pantalla
     * lo pedia y el selector de aprobadores del plan filtra justo por el. Un
     * supervisor nuevo se daba de alta y no aparecia nunca al asignar quien
     * firma — sin mensaje de error, simplemente no estaba en la lista.
     */
    public function test_un_supervisor_nuevo_queda_marcado_como_supervisor(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->formulario([
            'num_doc' => '10203041',
            'roles'   => [PersonRole::SUPERVISOR],
        ]));

        $persona = Person::where('num_doc', '10203041')->firstOrFail();

        $this->assertTrue($persona->hasRole(PersonRole::SUPERVISOR));
    }

    /**
     * Sin rol declarado, la persona nace SIN roles. Y esta bien asi.
     *
     * Nacia con «trabajador», que lo tenia el 100 % de la gente y por tanto no
     * separaba nada. Los roles dicen que APRUEBA alguien en el flujo, y un
     * trabajador de una contratista no aprueba nada: que estuvo en la obra lo
     * dice estar en la cuadrilla del plan, que es otro dato y otra tabla.
     */
    public function test_sin_rol_declarado_la_persona_nace_sin_roles(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->formulario(['num_doc' => '10203042']));

        $this->assertSame(
            0,
            Person::where('num_doc', '10203042')->firstOrFail()->roles()->count(),
        );
    }

    /** La edicion cambia la fila de verdad, no solo devuelve un 302. */
    public function test_la_edicion_guarda_los_cambios(): void
    {
        $persona = $this->persona();
        $this->actingAs($this->admin());

        $this->put(route('business_management.people.update', $persona->slug), $this->formulario([
            'name'     => 'Ana María',
            'lastname' => 'Quispe Flores',
            'num_doc'  => '47019236',
        ]))->assertRedirect(route('business_management.people.index'));

        $persona->refresh();
        $this->assertSame('Ana María', $persona->name);
        $this->assertSame('Quispe Flores', $persona->lastname);
        $this->assertSame('47019236', $persona->num_doc);
    }

    /** Falta el cargo: el error sale en su campo, no en una pantalla rota. */
    public function test_sin_cargo_el_alta_devuelve_el_error_en_su_campo(): void
    {
        $this->actingAs($this->admin());

        $datos = $this->formulario();
        unset($datos['position_id']);

        $this->from(route('business_management.people.create'))
            ->post(route('business_management.people.store'), $datos)
            ->assertSessionHasErrors('position_id');

        $this->assertDatabaseMissing('people', ['num_doc' => '10203040']);
    }

    /** Y el mismo documento no entra dos veces en el mismo pais. */
    public function test_el_mismo_documento_no_se_da_de_alta_dos_veces(): void
    {
        $this->persona('47019236');
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->formulario(['num_doc' => '47019236']))
            ->assertSessionHasErrors('num_doc');
    }

    /**
     * Un tipo de documento que no es de ese pais se rechaza **por su campo**.
     *
     * Antes la lista estaba escrita dentro del PHP (`DNI`, `CE`, `PASAPORTE`),
     * asi que el PTP —que en Peru llevan miles de venezolanos— no se podia usar
     * sin desplegar. Ahora es una fila del catalogo: se añade y funciona.
     */
    public function test_el_tipo_de_documento_sale_del_catalogo(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->formulario([
            'num_doc' => '900112233', 'doc_type' => 'PTP',
        ]))->assertSessionHasErrors('doc_type');

        DocumentType::create([
            'slug' => Str::random(22), 'country_id' => 1, 'code' => 'PTP',
            'name' => 'Permiso Temporal de Permanencia', 'min_length' => 9, 'max_length' => 12,
            'is_active' => true, 'created_by' => 1,
        ]);

        $this->post(route('business_management.people.store'), $this->formulario([
            'num_doc' => '900112233', 'doc_type' => 'PTP',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '900112233', 'doc_type' => 'PTP']);
    }

    /**
     * Con el catalogo de un pais vacio se admiten los tres de siempre.
     *
     * El desplegable ya se caia a ellos y la validacion no, asi que en una base
     * recien migrada la pantalla ofrecia «DNI», el servidor lo rechazaba por no
     * estar en el catalogo y no habia forma de dar de alta a nadie.
     */
    public function test_con_el_catalogo_vacio_se_puede_dar_de_alta_igual(): void
    {
        DocumentType::query()->forceDelete();
        $this->actingAs($this->admin());

        $this->post(route('business_management.people.store'), $this->formulario(['num_doc' => '10203043']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '10203043']);
    }

    /**
     * La cabecera «Documento» ordena.
     *
     * Junta tipo y numero, asi que no tiene columna propia: la peticion salia
     * con `sort=document`, el modelo no lo contemplaba y la lista volvia
     * exactamente igual. Sin error, sin nada.
     */
    public function test_el_listado_se_ordena_por_documento(): void
    {
        $this->persona('47019236');
        Person::create($this->base() + [
            'doc_type' => 'DNI', 'num_doc' => '10000001',
            'name' => 'Zoe', 'lastname' => 'Zapata', 'is_active' => true,
        ]);

        $orden = Person::query()
            ->filter(new \Illuminate\Http\Request(['sort' => 'document', 'direction' => 'asc']))
            ->pluck('num_doc')
            ->all();

        $this->assertSame(['10000001', '47019236'], $orden);
    }

    /**
     * Duplicar sirve para dar de alta al siguiente de la misma cuadrilla, asi
     * que el clon se lleva la empresa, el cargo y el rol. El documento no: es
     * lo unico que no comparten dos personas.
     */
    public function test_el_clon_conserva_empresa_cargo_y_rol(): void
    {
        $this->actingAs($this->admin());
        $this->post(route('business_management.people.store'), $this->formulario([
            'num_doc' => '10203044', 'roles' => [PersonRole::SUPERVISOR],
        ]));
        $original = Person::where('num_doc', '10203044')->firstOrFail();

        $this->post(route('business_management.people.duplicate', $original->slug));

        $clon = Person::where('id', '!=', $original->id)->latest('id')->firstOrFail();
        $this->assertNotSame($original->num_doc, $clon->num_doc);
        $this->assertTrue($clon->hasRole(PersonRole::SUPERVISOR));
        $this->assertDatabaseHas('person_company_links', [
            'person_id'   => $clon->id,
            'company_id'  => $this->empresa()->id,
            'position_id' => $this->cargo()->id,
        ]);
    }

    // ── El documento tapado ──────────────────────────────────────────────────

    /**
     * El listado no manda los documentos enteros al navegador.
     *
     * Es el fallo de fondo del modulo: la pantalla pintaba `******36` y el JSON
     * de Inertia —el `data-page` del HTML, que va entero al navegador— llevaba
     * el numero completo de las 391 personas. Tapar en la plantilla no tapa
     * nada.
     */
    public function test_el_listado_no_manda_el_documento_entero_sin_permiso(): void
    {
        $this->persona('47019236');

        $r = $this->actingAs($this->usuario(['people.view']))
            ->get(route('business_management.people.index'));

        $r->assertOk();
        $r->assertDontSee('47019236');
        $r->assertSee('******36');
    }

    /** Con el permiso, entero: para eso existe. */
    public function test_el_listado_manda_el_documento_entero_con_permiso(): void
    {
        $this->persona('47019236');

        $this->actingAs($this->usuario(['people.view', 'people.view_private_info']))
            ->get(route('business_management.people.index'))
            ->assertSee('47019236');
    }

    /** La ficha, igual. */
    public function test_la_ficha_no_manda_el_documento_entero_sin_permiso(): void
    {
        $persona = $this->persona('47019236');

        $this->actingAs($this->usuario(['people.view']))
            ->get(route('business_management.people.show', $persona->slug))
            ->assertDontSee('47019236');
    }

    /** Y la papelera, que es la otra lista con todos los documentos juntos. */
    public function test_la_papelera_no_manda_el_documento_entero_sin_permiso(): void
    {
        $persona = $this->persona('47019236');
        $persona->delete();

        // El super llega a la papelera por su rol; el documento se tapa igual,
        // porque `people.view_private_info` es el unico permiso que el bypass
        // del super NO concede.
        $rol = Role::firstOrCreate(['name' => 'super', 'guard_name' => 'web'], ['description' => 'Test super']);
        $rol->syncPermissions(Permission::whereNot('name', 'people.view_private_info')->get());
        $u = User::factory()->create(['tenant_id' => 1, 'country_id' => 1, 'locale_id' => 1]);
        $u->assignRole($rol);

        $this->actingAs($u)
            ->get(route('business_management.people.trash'))
            ->assertDontSee('47019236');
    }

    /**
     * **El fallo grave del modulo**: guardar borraba el documento.
     *
     * El formulario de edicion recibia el numero ya enmascarado —`******36`— en
     * un campo obligatorio y editable. Cualquiera con `people.edit` y sin
     * `people.view_private_info` que corrigiera un apellido guardaba los
     * asteriscos encima del DNI real: ocho caracteres, unico, la validacion lo
     * daba por bueno y no aparecia ningun error. El trabajador se quedaba sin
     * documento y nadie se enteraba hasta que no se le encontraba en la puerta.
     */
    public function test_guardar_sin_permiso_no_pisa_el_documento_con_la_mascara(): void
    {
        $persona = $this->persona('47019236');
        $usuario = $this->usuario(['people.view', 'people.edit']);

        // Tal cual lo mandaria la pantalla: con el valor que recibio, tapado.
        $this->actingAs($usuario)->put(
            route('business_management.people.update', $persona->slug),
            $this->formulario(['name' => 'Ana María', 'num_doc' => '******36']),
        );

        $persona->refresh();
        $this->assertSame('47019236', $persona->num_doc, 'la máscara se guardó encima del documento');
        $this->assertSame('Ana María', $persona->name, 'el resto del formulario sí tenía que guardarse');
    }

    /** Ni con un numero inventado: sin el permiso el documento no se toca. */
    public function test_sin_permiso_no_se_cambia_el_documento_ni_a_mano(): void
    {
        $persona = $this->persona('47019236');

        $this->actingAs($this->usuario(['people.view', 'people.edit']))->put(
            route('business_management.people.update', $persona->slug),
            $this->formulario(['num_doc' => '99999999', 'doc_type' => 'CE']),
        );

        $persona->refresh();
        $this->assertSame('47019236', $persona->num_doc);
        $this->assertSame('DNI', $persona->doc_type);
    }

    /** Con el permiso sí se corrige, que es justo para lo que está. */
    public function test_con_permiso_el_documento_se_corrige(): void
    {
        $persona = $this->persona('47019236');

        $this->actingAs($this->usuario(['people.view', 'people.edit', 'people.view_private_info']))->put(
            route('business_management.people.update', $persona->slug),
            $this->formulario(['num_doc' => '47019237']),
        );

        $this->assertSame('47019237', $persona->refresh()->num_doc);
    }
}
