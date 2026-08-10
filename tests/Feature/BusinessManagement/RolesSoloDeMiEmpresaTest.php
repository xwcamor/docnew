<?php

namespace Tests\Feature\BusinessManagement;

use App\Models\ApproverRole;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Person;
use App\Models\Position;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * El rol en obra es de la gente del propio workspace, y eso lo dice el servidor.
 *
 * Un rol dice que APRUEBA la persona, y quien autoriza un plan es del que
 * contrata: a un electricista de una contratista no se le pregunta que aprueba
 * porque no va a aprobar nada.
 *
 * La pantalla ya lo hacia —el campo sale deshabilitado con el motivo debajo—
 * pero SOLO la pantalla, y una regla que solo vive en el navegador no es una
 * regla. Bastaba un POST a mano, o un formulario abierto antes de cambiar la
 * empresa, para colar un supervisor de una contratista: a partir de ahi sale en
 * el selector de aprobadores del plan y ya esta firmando lo que no le toca.
 */
class RolesSoloDeMiEmpresaTest extends CatalogTestCase
{
    protected function moduleKey(): string
    {
        return 'people';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::firstOrCreate(
            ['country_id' => 1, 'scope' => DocumentType::PERSONA, 'code' => 'DNI'],
            ['slug' => Str::random(22), 'name' => 'DNI', 'min_length' => 8, 'max_length' => 8,
             'allowed_chars' => DocumentType::SOLO_CIFRAS, 'for_foreigners' => false,
             'is_active' => true, 'created_by' => 1],
        );

        ApproverRole::withoutGlobalScopes()->firstOrCreate(
            ['code' => 'supervisor'],
            ['slug' => Str::random(22), 'name_es' => 'Supervisor', 'name_en' => 'Supervisor',
             'sort_order' => 1, 'is_active' => true, 'created_by' => 1],
        );
    }

    protected function unaFila(): \Illuminate\Database\Eloquent\Model
    {
        return Person::create($this->base() + [
            'name' => 'Ana', 'lastname' => 'Quispe', 'doc_type' => 'DNI', 'num_doc' => '45871236',
        ]);
    }

    private function empresa(string $nombre, string $ruc): Company
    {
        return Company::firstOrCreate(
            ['num_doc' => $ruc],
            $this->base() + ['name' => $nombre, 'complete_name' => $nombre . ' SAC'],
        );
    }

    /** @param array<string, mixed> $extra */
    private function alta(array $extra): \Illuminate\Testing\TestResponse
    {
        $cargo = Position::firstOrCreate(['code' => 'Electricista'], $this->base());

        return $this->actingAs($this->admin())
            ->post(route('business_management.people.store'), $extra + [
                'name' => 'Luis', 'lastname' => 'Pérez', 'country_id' => 1,
                'doc_type' => 'DNI', 'is_active' => true, 'position_id' => $cargo->id,
            ]);
    }

    /** Con la empresa del workspace marcada, la contratista no reparte roles. */
    public function test_una_contratista_no_puede_tener_supervisores(): void
    {
        $mia = $this->empresa('Mi empresa', '20100000001');
        $contratista = $this->empresa('Contratista', '20100000002');
        Tenant::find(1)->update(['company_id' => $mia->id]);

        $this->alta([
            'num_doc' => '45871250', 'company_id' => $contratista->id, 'roles' => ['supervisor'],
        ])->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('people', ['num_doc' => '45871250']);
    }

    /** La gente de la propia empresa sí. */
    public function test_la_gente_de_mi_empresa_si(): void
    {
        $mia = $this->empresa('Mi empresa', '20100000001');
        Tenant::find(1)->update(['company_id' => $mia->id]);

        $this->alta([
            'num_doc' => '45871251', 'company_id' => $mia->id, 'roles' => ['supervisor'],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '45871251']);
    }

    /** Y una persona de la contratista SIN roles entra sin problema. */
    public function test_la_contratista_da_de_alta_gente_sin_roles(): void
    {
        $mia = $this->empresa('Mi empresa', '20100000001');
        $contratista = $this->empresa('Contratista', '20100000002');
        Tenant::find(1)->update(['company_id' => $mia->id]);

        $this->alta([
            'num_doc' => '45871252', 'company_id' => $contratista->id, 'roles' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', ['num_doc' => '45871252']);
    }

    /**
     * Sin «Mi empresa» marcada no hay contra que comparar, y se deja pasar.
     *
     * Es lo mismo que hace la pantalla: preferible a que nadie pueda dar de alta
     * a un supervisor hasta que alguien rellene un ajuste que quiza ni sabe que
     * existe.
     */
    public function test_sin_empresa_marcada_no_se_corta_a_nadie(): void
    {
        $cualquiera = $this->empresa('Contratista', '20100000002');
        Tenant::find(1)->update(['company_id' => null]);

        $this->alta([
            'num_doc' => '45871253', 'company_id' => $cualquiera->id, 'roles' => ['supervisor'],
        ])->assertSessionHasNoErrors();
    }

    /** La edición va por la misma puerta: no vale colarlo al corregir el apellido. */
    public function test_la_edicion_tampoco_deja_colarlo(): void
    {
        $mia = $this->empresa('Mi empresa', '20100000001');
        $contratista = $this->empresa('Contratista', '20100000002');
        Tenant::find(1)->update(['company_id' => $mia->id]);

        $persona = Person::create($this->base() + [
            'name' => 'Luis', 'lastname' => 'Pérez', 'doc_type' => 'DNI', 'num_doc' => '45871254',
        ]);
        $cargo = Position::firstOrCreate(['code' => 'Electricista'], $this->base());

        $this->actingAs($this->admin())
            ->put(route('business_management.people.update', $persona), [
                'name' => 'Luis', 'lastname' => 'Pérez Gómez', 'country_id' => 1,
                'doc_type' => 'DNI', 'num_doc' => '45871254', 'is_active' => true,
                'company_id' => $contratista->id, 'position_id' => $cargo->id,
                'roles' => ['supervisor'],
            ])
            ->assertSessionHasErrors('roles');
    }
}
